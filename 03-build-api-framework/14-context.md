# Context

[Context](https://blog.golang.org/context) adalah salah satu konkuresi pattern yang bertujuan untuk mengcancel jika menemui sebuah routine yang waktu eksekusinya lama. Karena operasi yang berjalan lama memang seharusnya diberi deadline. Jalan untuk meng-handle pembatalan adalah dengan melempar context.Context to fungsi yang mengetahui proses untuk mengecek pembatalan terminasi dini.

* Tambahkan argumen context.Context ke semua fungsi, taruh sebagi argumen pertama, misalnya `List() ([]model.User, error)` diubah menjadi `List(ctx context.Context) ([]model.User, error)`

* Passing ctx variable ke db.QueryContext, db.QueryRowContext, db.PrepareContext and stmt.ExecContext di repository (file `internal/repository/user_repository.go`)

* Di setiap fungsi yang sekiranya mengambil waktu lama, seperti heavy io, tambahkan select case untuk membaca apakah context sudah berakhir.

```go
    select {
	case <-ctx.Done():
		return nil, ctx.Err()
	default:
	}
```

 * Fungsi yang memerlukan select dengan ctx.Done() adalah :
    1. Operasi I/O yang lama (database query, HTTP request, file I/O)
    2. Operasi yang memanggil external service (API call, RPC)
    3. Fungsi dengan goroutine/channel (waiting for result)
    4. Operasi yang bisa di-cancel/timed out

* Dalam contoh fitur saat ini, kita hanya membuat simple CRUD yang mana tidak perlu dihandle melalui select dengan ctx.Done(). Potongan kode di atas hanya contoh saja, dan tidak akan diguankan dalam  project ini. Namun sebagai antisipasi jika suatu saat kita menumkan case yang membutuhkan handling Select context, kita oerlu menambahkan bisnis error kita dengan Gateway Timeout, mengingat error context ini paling tepat mengembalikan response 504 gateway Timeout.  

* Semua `context.Background()` dalam pemanggilan log, diubah untuk meneruskan context yang dikirim dari argumen, misalnya `u.log.Error(context.Background(), "error: querying users", slog.Any("error", err))` diubah menjadi `u.log.Error(ctx, "error: querying users", slog.Any("error", err))` 

* Ubah file `internal/repository/user_repository.go` untuk mengimpmentasikan context

```go
package repository

import (
	"context"
	"database/sql"
	"log/slog"
	"workshop/internal/model"

	"github.com/jacky-htg/go-libs/logger"
)

type UserRepository interface {
	List(ctx context.Context) ([]model.User, error)
	Create(ctx context.Context, user *model.User) error
	FindById(ctx context.Context, id string) (*model.User, error)
	Update(ctx context.Context, user *model.User) error
	Delete(ctx context.Context, id string) error
}

type userRepository struct {
	db  *sql.DB
	log logger.Logger
}

func NewUserRepository(db *sql.DB, log logger.Logger) UserRepository {
	return &userRepository{db: db, log: log}
}

// List : http handler for returning list of users
func (u *userRepository) List(ctx context.Context) ([]model.User, error) {
	query := `SELECT id, name, username, password, email, is_active FROM users WHERE deleted_at IS NULL`
	rows, err := u.db.QueryContext(ctx, query)
	if err != nil {
		u.log.Error(ctx, "error: querying users", slog.Any("error", err))
		return nil, err
	}
	defer rows.Close()

	var users []model.User
	for rows.Next() {
		var user model.User
		if err := rows.Scan(&user.ID, &user.Name, &user.Username, &user.Password, &user.Email, &user.IsActive); err != nil {

			u.log.Error(ctx, "error: scanning user row", slog.Any("error", err))
			return nil, err
		}
		users = append(users, user)
	}

	if err := rows.Err(); err != nil {
		u.log.Error(ctx, "error: iterating user rows", slog.Any("error", err))
		return nil, err
	}

	return users, nil
}

func (u *userRepository) Create(ctx context.Context, user *model.User) error {
	query := `INSERT INTO users (id, name, username, password, email, is_active) VALUES ($1, $2, $3, $4, $5, $6)`
	_, err := u.db.ExecContext(ctx, query, user.ID, user.Name, user.Username, user.Password, user.Email, user.IsActive)
	if err != nil {
		u.log.Error(ctx, "error: inserting user", slog.Any("error", err))
		return err
	}

	return nil
}

func (u *userRepository) FindById(ctx context.Context, id string) (*model.User, error) {
	query := `SELECT id, name, username, password, email, is_active FROM users WHERE id = $1 AND deleted_at IS NULL`
	row := u.db.QueryRowContext(ctx, query, id)

	var user model.User
	if err := row.Scan(&user.ID, &user.Name, &user.Username, &user.Password, &user.Email, &user.IsActive); err != nil {
		if err == sql.ErrNoRows {
			return nil, nil
		}
		u.log.Error(ctx, "error: scanning user row", slog.Any("error", err))
		return nil, err
	}

	return &user, nil
}

func (u *userRepository) Update(ctx context.Context, user *model.User) error {
	query := `UPDATE users SET name = $1, is_active = $2 WHERE id = $3 RETURNING username, email`
	err := u.db.QueryRowContext(ctx, query, user.Name, user.IsActive, user.ID).Scan(&user.Username, &user.Email)
	if err != nil {
		u.log.Error(ctx, "error: updating user", slog.Any("error", err))
		return err
	}

	return nil
}

func (u *userRepository) Delete(ctx context.Context, id string) error {
	query := `UPDATE users SET deleted_at = timezone('utc', now()) WHERE id = $1`
	_, err := u.db.ExecContext(ctx, query, id)
	if err != nil {
		u.log.Error(ctx, "error: deleting user", slog.Any("error", err))
		return err
	}

	return nil
}
```

* Ubah file `internal/service/users.go` untuk mengimplmentasikan contaxt

```go
package service

import (
	"context"
	"log/slog"
	"workshop/internal/model"
	"workshop/internal/repository"
	"workshop/pkg/errors"

	"github.com/jacky-htg/go-libs/logger"
	"github.com/jacky-htg/go-libs/uuid7"
	"golang.org/x/crypto/bcrypt"
)

type Users interface {
	List(ctx context.Context) ([]model.User, *errors.BusinessError)
	Create(ctx context.Context, user *model.User) *errors.BusinessError
	FindById(ctx context.Context, id string) (*model.User, *errors.BusinessError)
	Update(ctx context.Context, user *model.User) *errors.BusinessError
	Delete(ctx context.Context, id string) *errors.BusinessError
}

type users struct {
	log  logger.Logger
	repo repository.UserRepository
}

func NewUsers(repo repository.UserRepository, log logger.Logger) Users {
	return &users{repo: repo, log: log}
}

func (u *users) List(ctx context.Context) ([]model.User, *errors.BusinessError) {
	users, err := u.repo.List(ctx)
	if err != nil {
		return nil, errors.InternalServerErrorWrap(err, "error listing users")
	}
	return users, nil
}

func (u *users) Create(ctx context.Context, user *model.User) *errors.BusinessError {
	pass, err := bcrypt.GenerateFromPassword([]byte(user.Password), bcrypt.DefaultCost)
	if err != nil {
		u.log.Error(ctx, "error generate password", slog.Any("error", err))
		return errors.InternalServerErrorWrap(err, "error generating password")
	}

	user.ID = uuid7.New()
	user.Password = string(pass)

	if err := u.repo.Create(ctx, user); err != nil {
		return errors.InternalServerErrorWrap(err, "error creating user")
	}

	return nil
}

func (u *users) FindById(ctx context.Context, id string) (*model.User, *errors.BusinessError) {
	user, err := u.repo.FindById(ctx, id)
	if err != nil {
		return nil, errors.InternalServerErrorWrap(err, "error finding user")
	}
	if user == nil {
		return nil, errors.NotFound("user not found")
	}
	return user, nil
}

func (u *users) Update(ctx context.Context, user *model.User) *errors.BusinessError {
	existUser, err := u.repo.FindById(ctx, user.ID)
	if err != nil {
		return errors.InternalServerErrorWrap(err, "error finding user")
	}
	if existUser == nil {
		return errors.NotFound("user not found")
	}
	err = u.repo.Update(ctx, user)
	if err != nil {
		return errors.InternalServerErrorWrap(err, "error updating user")
	}
	return nil
}

func (u *users) Delete(ctx context.Context, id string) *errors.BusinessError {
	existUser, err := u.repo.FindById(ctx, id)
	if err != nil {
		return errors.InternalServerErrorWrap(err, "error finding user")
	}
	if existUser == nil {
		return errors.NotFound("user not found")
	}
	err = u.repo.Delete(ctx, id)
	if err != nil {
		return errors.InternalServerErrorWrap(err, "error deleting user")
	}
	return nil
}
```

* Ubah file `internal/handler/user_handler.go` untuk mengimpmentasikan contaxt

```go
package handler

import (
	"encoding/json"
	"log/slog"
	"net/http"

	"workshop/internal/dto"
	"workshop/internal/model"
	"workshop/internal/service"
	"workshop/pkg/errors"
	"workshop/pkg/response"

	"github.com/jacky-htg/go-libs/logger"
)

type UserHandler interface {
	List(w http.ResponseWriter, r *http.Request)
	Create(w http.ResponseWriter, r *http.Request)
	FindById(w http.ResponseWriter, r *http.Request)
	Update(w http.ResponseWriter, r *http.Request)
	Delete(w http.ResponseWriter, r *http.Request)
}

type userHandler struct {
	log     logger.Logger
	service service.Users
}

func NewUserHandler(service service.Users, log logger.Logger) UserHandler {
	return &userHandler{service: service, log: log}
}

// List : http handler for returning list of users
func (u *userHandler) List(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	users, err := u.service.List(ctx)
	if err != nil {
		u.log.Error(ctx, "error: listing users", slog.Any("error", err))
		response.SetError(ctx, u.log, w, err)
		return
	}

	var resp []dto.UserResponse
	for _, user := range users {
		var ur dto.UserResponse
		ur.Transform(user)
		resp = append(resp, ur)
	}

	response.SetOk(ctx, u.log, w, resp)
}

// Create : http handler for creating a new user
func (u *userHandler) Create(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	var req dto.UserRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		u.log.Error(ctx, "error: decoding user request", slog.Any("error", err))
		response.SetError(ctx, u.log, w, errors.InvalidInputWrap(err))
		return
	}
	user := model.User{}
	req.Transform(&user)
	err := u.service.Create(ctx, &user)
	if err != nil {
		response.SetError(ctx, u.log, w, err)
		return
	}

	var resp dto.UserResponse
	resp.Transform(user)
	response.SetCreated(ctx, u.log, w, resp)
}

// FindById : http handler for finding a user by ID
func (u *userHandler) FindById(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id := r.PathValue("id")
	if id == "" {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Missing id parameter"))
		return
	}

	user, err := u.service.FindById(ctx, id)
	if err != nil {
		response.SetError(ctx, u.log, w, err)
		return
	}

	var resp dto.UserResponse
	resp.Transform(*user)

	response.SetOk(ctx, u.log, w, resp)
}

func (u *userHandler) Update(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id := r.PathValue("id")
	if id == "" {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Missing id parameter"))
		return
	}

	var req dto.UserUpdateRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		u.log.Error(ctx, "error: decoding user request", slog.Any("error", err))
		response.SetError(ctx, u.log, w, errors.InvalidInputWrap(err))
		return
	}
	user := model.User{ID: id}
	req.Transform(&user)
	err := u.service.Update(ctx, &user)
	if err != nil {
		response.SetError(ctx, u.log, w, err)
		return
	}

	var resp dto.UserResponse
	resp.Transform(user)

	response.SetOk(ctx, u.log, w, resp)
}

func (u *userHandler) Delete(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id := r.PathValue("id")
	if id == "" {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Missing id parameter"))
		return
	}

	err := u.service.Delete(ctx, id)
	if err != nil {
		response.SetError(ctx, u.log, w, err)
		return
	}
	response.SetOk(ctx, u.log, w, struct{}{})
}
```

* Ubah file `pkg/response/response.go` untuk mengimplmentasikan context

```go
package response

import (
	"context"
	"encoding/json"
	"log/slog"
	"net/http"
	"workshop/pkg/errors"

	"github.com/jacky-htg/go-libs/logger"
)

const AppBusinessStatusSuccess = "B1"

type StandardResponse struct {
	Status  string `json:"status"`
	Message string `json:"message"`
	Data    any    `json:"data"`
}

func SetResponse(ctx context.Context, log logger.Logger, w http.ResponseWriter, httpStatus int, appBusinessLogicStatus string, message string, data any) {
	standardResponse := StandardResponse{
		Status:  appBusinessLogicStatus,
		Message: message,
		Data:    data,
	}

	resp, err := json.Marshal(standardResponse)
	if err != nil {
		log.Error(ctx, "error: marshaling users to JSON", slog.Any("error", err))
		httpStatus = http.StatusInternalServerError
		appBusinessLogicStatus = errors.InternalServerErrorCode
		message = "Internal Server Error"
	}

	w.Header().Set("Content-Type", "application/json")
	if httpStatus != http.StatusOK {
		w.WriteHeader(httpStatus)
	}

	if _, err = w.Write(resp); err != nil {
		log.Error(ctx, "error: writing response", slog.Any("error", err))
	}
}

func SetError(ctx context.Context, log logger.Logger, w http.ResponseWriter, err *errors.BusinessError, message ...string) {
	finalMessage := ""
	if len(message) > 0 && len(message[0]) > 0 {
		finalMessage = message[0]
	}

	if finalMessage == "" && err != nil {
		finalMessage = err.Message
	}
	SetResponse(ctx, log, w, err.HTTPStatus, err.Code, finalMessage, struct{}{})
}

func SetOk(ctx context.Context, log logger.Logger, w http.ResponseWriter, data any) {
	SetResponse(ctx, log, w, http.StatusOK, AppBusinessStatusSuccess, "Success", data)
}

func SetCreated(ctx context.Context, log logger.Logger, w http.ResponseWriter, data any) {
	SetResponse(ctx, log, w, http.StatusCreated, AppBusinessStatusSuccess, "Created", data)
}
```

* Ubah file `pkg/errors/error.go` untuk menambahkan error Gateway Timeout

```go
package errors

import (
	"errors"
	"fmt"
	"net/http"
)

const (
	InternalServerErrorCode = "E000"
	InvalidInputCode        = "E001"
	NotFoundCode            = "E002"
	ForbiddenCode           = "E003"
	UnauthorizedCode        = "E004"
	GatewayTimeoutCode      = "E005"
)

// Default messages
const (
	InternalServerErrorMessage = "Internal Server Error"
	InvalidInputMessage        = "Invalid input"
	NotFoundMessage            = "Resource not found"
	ForbiddenMessage           = "Forbidden"
	UnauthorizedMessage        = "Unauthorized"
	GatewayTimeoutMessage      = "Gateway Timeout"
)

type BusinessError struct {
	Err        error
	Code       string
	Message    string
	HTTPStatus int
}

// Error implements error interface
func (err *BusinessError) Error() string {
	if err.Err != nil {
		return fmt.Sprintf("[%s] %s: %v", err.Code, err.Message, err.Err)
	}
	return fmt.Sprintf("[%s] %s", err.Code, err.Message)
}

// Unwrap untuk error wrapping (Go 1.13+)
func (err *BusinessError) Unwrap() error {
	return err.Err
}

// Helper functions untuk create error
func ErrNew(code string, message string, httpStatus int) *BusinessError {
	return &BusinessError{
		Err:        fmt.Errorf("%s", message),
		Code:       code,
		Message:    message,
		HTTPStatus: httpStatus,
	}
}

func ErrWrap(err error, bErr *BusinessError) {
	bErr.Err = err
}

// Quick constructors tanpa wrap
func InvalidInput(message ...string) *BusinessError {
	finalMessage := InvalidInputMessage
	if len(message) > 0 && len(message[0]) > 0 {
		finalMessage = message[0]
	}
	return ErrNew(InvalidInputCode, finalMessage, http.StatusBadRequest)
}

func NotFound(message ...string) *BusinessError {
	finalMessage := NotFoundMessage
	if len(message) > 0 && len(message[0]) > 0 {
		finalMessage = message[0]
	}
	return ErrNew(NotFoundCode, finalMessage, http.StatusNotFound)
}

func Forbidden(message ...string) *BusinessError {
	finalMessage := ForbiddenMessage
	if len(message) > 0 && len(message[0]) > 0 {
		finalMessage = message[0]
	}
	return ErrNew(ForbiddenCode, finalMessage, http.StatusForbidden)
}

func Unauthorized(message ...string) *BusinessError {
	finalMessage := UnauthorizedMessage
	if len(message) > 0 && len(message[0]) > 0 {
		finalMessage = message[0]
	}
	return ErrNew(UnauthorizedCode, finalMessage, http.StatusUnauthorized)
}

func InternalServerError(message ...string) *BusinessError {
	finalMessage := InternalServerErrorMessage
	if len(message) > 0 && len(message[0]) > 0 {
		finalMessage = message[0]
	}
	return ErrNew(InternalServerErrorCode, finalMessage, http.StatusInternalServerError)
}

func GatewayTimeout(message ...string) *BusinessError {
	finalMessage := GatewayTimeoutMessage
	if len(message) > 0 && len(message[0]) > 0 {
		finalMessage = message[0]
	}
	return ErrNew(GatewayTimeoutCode, finalMessage, http.StatusGatewayTimeout)
}

// Wrapped constructors
func InvalidInputWrap(err error, message ...string) *BusinessError {
	bErr := InvalidInput(message...)
	ErrWrap(err, bErr)
	return bErr
}

func NotFoundWrap(err error, message ...string) *BusinessError {
	bErr := NotFound(message...)
	ErrWrap(err, bErr)
	return bErr
}

func ForbiddenWrap(err error, message ...string) *BusinessError {
	bErr := Forbidden(message...)
	ErrWrap(err, bErr)
	return bErr
}

func UnauthorizedWrap(err error, message ...string) *BusinessError {
	bErr := Unauthorized(message...)
	ErrWrap(err, bErr)
	return bErr
}

func InternalServerErrorWrap(err error, message ...string) *BusinessError {
	bErr := InternalServerError(message...)
	ErrWrap(err, bErr)
	return bErr
}

func GatewayTimeoutWrap(err error, message ...string) *BusinessError {
	bErr := GatewayTimeout(message...)
	ErrWrap(err, bErr)
	return bErr
}

// GetBusinessError extracts BusinessError from error chain
func GetBusinessError(err error) (*BusinessError, bool) {
	var bizErr *BusinessError
	if errors.As(err, &bizErr) {
		return bizErr, true
	}
	return nil, false
}
```

