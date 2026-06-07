# CRUD

Pada bab ini kita akan melengkapi method Users.Create, Users.View, Users.Update dan Users.Delete

## Create

* Saat create ada logic hashing password dan genrate id dengan uuid7.
* Pertama kita update file `internal/repository/user_repository.go` dan tambahkan method Create.

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
	List() ([]model.User, error)
	Create(*model.User) error
}

type userRepository struct {
	db  *sql.DB
	log logger.Logger
}

func NewUserRepository(db *sql.DB, log logger.Logger) UserRepository {
	return &userRepository{db: db, log: log}
}

// List : http handler for returning list of users
func (u *userRepository) List() ([]model.User, error) {
	query := `SELECT id, name, username, password, email, is_active FROM users`
	rows, err := u.db.Query(query)
	if err != nil {
		u.log.Error(context.Background(), "error: querying users", slog.Any("error", err))
		return nil, err
	}
	defer rows.Close()

	var users []model.User
	for rows.Next() {
		var user model.User
		if err := rows.Scan(&user.ID, &user.Name, &user.Username, &user.Password, &user.Email, &user.IsActive); err != nil {

			u.log.Error(context.Background(), "error: scanning user row", slog.Any("error", err))
			return nil, err
		}
		users = append(users, user)
	}

	if err := rows.Err(); err != nil {
		u.log.Error(context.Background(), "error: iterating user rows", slog.Any("error", err))
		return nil, err
	}

	return users, nil
}

func (u *userRepository) Create(user *model.User) error {
	query := `INSERT INTO users (id, name, username, password, email, is_active) VALUES ($1, $2, $3, $4, $5, $6)`
	_, err := u.db.Exec(query, user.ID, user.Name, user.Username, user.Password, user.Email, user.IsActive)
	if err != nil {
		u.log.Error(context.Background(), "error: inserting user", slog.Any("error", err))
		return err
	}

	return nil
}
```

* Ubah file `internal/service/users.go` untuk menambahkan method Create

```go
package service

import (
	"context"
	"log/slog"
	"workshop/internal/model"
	"workshop/internal/repository"

	"github.com/jacky-htg/go-libs/logger"
	"github.com/jacky-htg/go-libs/uuid7"
	"golang.org/x/crypto/bcrypt"
)

type Users interface {
	List() ([]model.User, error)
	Create(*model.User) error
}

type users struct {
	log  logger.Logger
	repo repository.UserRepository
}

func NewUsers(repo repository.UserRepository, log logger.Logger) Users {
	return &users{repo: repo, log: log}
}

func (u *users) List() ([]model.User, error) {
	return u.repo.List()
}

func (u *users) Create(user *model.User) error {

	pass, err := bcrypt.GenerateFromPassword([]byte(user.Password), bcrypt.DefaultCost)
	if err != nil {
		u.log.Error(context.Background(), "error generate password", slog.Any("error", err))
		return err
	}

	user.ID = uuid7.New()
	user.Password = string(pass)

	if err := u.repo.Create(user); err != nil {
		return err
	}

	return nil
}
```

* Selanjutnya kita buat file `internal/dto/user_request.go` yang berisi:

```go
package dto

import "workshop/internal/model"

type UserRequest struct {
	Name     string `json:"name"`
	Username string `json:"username"`
	Password string `json:"password"`
	Email    string `json:"email"`
	IsActive bool   `json:"is_active"`
}

func (u *UserRequest) Transform(user *model.User) {
	user.Name = u.Name
	user.Username = u.Username
	user.Password = u.Password
	user.Email = u.Email
	user.IsActive = u.IsActive
}
```

* Selanjutnya ubah file `internal/handler/user_handler.go` untuk menmbahkan method Create

```go
package handler

import (
	"context"
	"encoding/json"
	"log/slog"
	"net/http"
	"workshop/internal/dto"
	"workshop/internal/service"

	"github.com/jacky-htg/go-libs/logger"
)

type UserHandler interface {
	List(w http.ResponseWriter, r *http.Request)
	Create(w http.ResponseWriter, r *http.Request)
}

type userHandler struct {
	log     logger.Logger
	service service.Users
}

func NewUserHandler(service service.Users, log logger.Logger) UserHanlder {
	return &userHandler{service: service, log: log}
}

// List : http handler for returning list of users
func (u *userHandler) List(w http.ResponseWriter, r *http.Request) {
	users, err := u.service.List()
	if err != nil {
		u.log.Error(context.Background(), "error: listing users", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	var response []dto.UserResponse
	for _, user := range users {
		var ur dto.UserResponse
		ur.Transform(user)
		response = append(response, ur)
	}

	data, err := json.Marshal(response)
	if err != nil {
		u.log.Error(context.Background(), "error: marshaling users to JSON", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	if _, err := w.Write(data); err != nil {
		u.log.Error(context.Background(), "error: writing response", slog.Any("error", err))
	}
}

// Create : http handler for creating a new user
func (u *userHandler) Create(w http.ResponseWriter, r *http.Request) {
	var req dto.UserRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		u.log.Error(context.Background(), "error: decoding user request", slog.Any("error", err))
		http.Error(w, "Bad Request", http.StatusBadRequest)
		return
	}

	user := model.User{}
	req.Transform(&user)
	err := u.service.Create(&user)
	if err != nil {
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	var response dto.UserResponse
	response.Transform(user)

	data, err := json.Marshal(response)
	if err != nil {
		u.log.Error(context.Background(), "error: marshaling user to JSON", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusCreated)
	if _, err := w.Write(data); err != nil {
		u.log.Error(context.Background(), "error: writing response", slog.Any("error", err))
	}
}
```

* Selanjutnya tambahkan routing `POST /users` di file `internal/router/api.go`

```go
package router

import (
	"database/sql"
	"net/http"
	"workshop/config"
	"workshop/internal/handler"
	"workshop/internal/repository"
	"workshop/internal/service"

	"github.com/jacky-htg/go-libs/logger"
)

func Api(
	cfg config.Config,
	db *sql.DB,
	log logger.Logger,
) http.Handler {
	mux := http.NewServeMux()

	userRepository := repository.NewUserRepository(db, log)
	userService := service.NewUsers(userRepository, log)
	userHandler := handler.NewUserHandler(userService, log)
	mux.HandleFunc("GET /users", userHandler.List)
	mux.HandleFunc("POST /users", userHandler.Create)

	return mux
}
```

* Untuk mengetes, api bisa dipanggil melalui postman atau curl

```bash
curl --location 'localhost:9000/users' \
--header 'Content-Type: application/json' \
--data-raw '{
    "name": "jet",
    "username": "jet",
    "email": "jet@example.com",
    "password": "1234",
    "is_active": true
}'
```

## Read

* Kita sudah punya endpoint `GET /users` untuk membaca seluruh data users. Sekarang kita akan menambahkan routing `GET /users/{id}`
* Ubah file `internal/repository/user_repository.go` untuk menambahkan method FindById

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
	List() ([]model.User, error)
	Create(*model.User) error
	FindById(id string) (*model.User, error)
}

type userRepository struct {
	db  *sql.DB
	log logger.Logger
}

func NewUserRepository(db *sql.DB, log logger.Logger) UserRepository {
	return &userRepository{db: db, log: log}
}

// List : http handler for returning list of users
func (u *userRepository) List() ([]model.User, error) {
	query := `SELECT id, name, username, password, email, is_active FROM users`
	rows, err := u.db.Query(query)
	if err != nil {
		u.log.Error(context.Background(), "error: querying users", slog.Any("error", err))
		return nil, err
	}
	defer rows.Close()

	var users []model.User
	for rows.Next() {
		var user model.User
		if err := rows.Scan(&user.ID, &user.Name, &user.Username, &user.Password, &user.Email, &user.IsActive); err != nil {

			u.log.Error(context.Background(), "error: scanning user row", slog.Any("error", err))
			return nil, err
		}
		users = append(users, user)
	}

	if err := rows.Err(); err != nil {
		u.log.Error(context.Background(), "error: iterating user rows", slog.Any("error", err))
		return nil, err
	}

	return users, nil
}

func (u *userRepository) Create(user *model.User) error {
	query := `INSERT INTO users (id, name, username, password, email, is_active) VALUES ($1, $2, $3, $4, $5, $6)`
	_, err := u.db.Exec(query, user.ID, user.Name, user.Username, user.Password, user.Email, user.IsActive)
	if err != nil {
		u.log.Error(context.Background(), "error: inserting user", slog.Any("error", err))
		return err
	}

	return nil
}

func (u *userRepository) FindById(id string) (*model.User, error) {
	query := `SELECT id, name, username, password, email, is_active FROM users WHERE id = $1`
	row := u.db.QueryRow(query, id)

	var user model.User
	if err := row.Scan(&user.ID, &user.Name, &user.Username, &user.Password, &user.Email, &user.IsActive); err != nil {
		if err == sql.ErrNoRows {
			return nil, nil
		}
		u.log.Error(context.Background(), "error: scanning user row", slog.Any("error", err))
		return nil, err
	}

	return &user, nil
}
```

* Ubah file `internal/service/users.go` untuk menambahkan method FindById

```go
package service

import (
	"context"
	"log/slog"
	"workshop/internal/model"
	"workshop/internal/repository"

	"github.com/jacky-htg/go-libs/logger"
	"github.com/jacky-htg/go-libs/uuid7"
	"golang.org/x/crypto/bcrypt"
)

type Users interface {
	List() ([]model.User, error)
	Create(*model.User) error
	FindById(id string) (*model.User, error)
}

type users struct {
	log  logger.Logger
	repo repository.UserRepository
}

func NewUsers(repo repository.UserRepository, log logger.Logger) Users {
	return &users{repo: repo, log: log}
}

func (u *users) List() ([]model.User, error) {
	return u.repo.List()
}

func (u *users) Create(user *model.User) error {

	pass, err := bcrypt.GenerateFromPassword([]byte(user.Password), bcrypt.DefaultCost)
	if err != nil {
		u.log.Error(context.Background(), "error generate password", slog.Any("error", err))
		return err
	}

	user.ID = uuid7.New()
	user.Password = string(pass)

	if err := u.repo.Create(user); err != nil {
		return err
	}

	return nil
}

func (u *users) FindById(id string) (*model.User, error) {
	return u.repo.FindById(id)
}
```

* Ubah file `internal/handler/user_handler.go` untuk menambhakan method FindById

```go
package handler

import (
	"context"
	"encoding/json"
	"log/slog"
	"net/http"
	"workshop/internal/dto"
	"workshop/internal/service"

	"github.com/jacky-htg/go-libs/logger"
)

type UserHandler interface {
	List(w http.ResponseWriter, r *http.Request)
	Create(w http.ResponseWriter, r *http.Request)
	FindById(w http.ResponseWriter, r *http.Request)
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
	users, err := u.service.List()
	if err != nil {
		u.log.Error(context.Background(), "error: listing users", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	var response []dto.UserResponse
	for _, user := range users {
		var ur dto.UserResponse
		ur.Transform(user)
		response = append(response, ur)
	}

	data, err := json.Marshal(response)
	if err != nil {
		u.log.Error(context.Background(), "error: marshaling users to JSON", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	if _, err := w.Write(data); err != nil {
		u.log.Error(context.Background(), "error: writing response", slog.Any("error", err))
	}
}

// Create : http handler for creating a new user
func (u *userHandler) Create(w http.ResponseWriter, r *http.Request) {
	var req dto.UserRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		u.log.Error(context.Background(), "error: decoding user request", slog.Any("error", err))
		http.Error(w, "Bad Request", http.StatusBadRequest)
		return
	}

	user := model.User{}
	req.Transform(&user)
	err := u.service.Create(&user)
	if err != nil {
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	var response dto.UserResponse
	response.Transform(user)

	data, err := json.Marshal(response)
	if err != nil {
		u.log.Error(context.Background(), "error: marshaling user to JSON", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusCreated)
	if _, err := w.Write(data); err != nil {
		u.log.Error(context.Background(), "error: writing response", slog.Any("error", err))
	}
}

// FindById : http handler for finding a user by ID
func (u *userHandler) FindById(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	if id == "" {
		http.Error(w, "Bad Request: missing id parameter", http.StatusBadRequest)
		return
	}

	user, err := u.service.FindById(id)
	if err != nil {
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}
	if user == nil {
		http.Error(w, "Not Found", http.StatusNotFound)
		return
	}

	var response dto.UserResponse
	response.Transform(*user)

	data, err := json.Marshal(response)
	if err != nil {
		u.log.Error(context.Background(), "error: marshaling user to JSON", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	if _, err := w.Write(data); err != nil {
		u.log.Error(context.Background(), "error: writing response", slog.Any("error", err))
	}
}
```

* Ubah file `internal/router/api.go` untuk menambahkan route `GET /users/{id}`

```go
package router

import (
	"database/sql"
	"net/http"
	"workshop/config"
	"workshop/internal/handler"
	"workshop/internal/repository"
	"workshop/internal/service"

	"github.com/jacky-htg/go-libs/logger"
)

func Api(
	cfg config.Config,
	db *sql.DB,
	log logger.Logger,
) http.Handler {
	mux := http.NewServeMux()

	userRepository := repository.NewUserRepository(db, log)
	userService := service.NewUsers(userRepository, log)
	userHandler := handler.NewUserHandler(userService, log)
	mux.HandleFunc("GET /users", userHandler.List)
	mux.HandleFunc("POST /users", userHandler.Create)
	mux.HandleFunc("GET /users/{id}", userHandler.FindById)

	return mux
}
```

## Update

* Ubah file `internal/repository/user_repository.go` untuk menambahkan method Update

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
	List() ([]model.User, error)
	Create(*model.User) error
	FindById(id string) (*model.User, error)
	Update(*model.User) error
}

type userRepository struct {
	db  *sql.DB
	log logger.Logger
}

func NewUserRepository(db *sql.DB, log logger.Logger) UserRepository {
	return &userRepository{db: db, log: log}
}

// List : http handler for returning list of users
func (u *userRepository) List() ([]model.User, error) {
	query := `SELECT id, name, username, password, email, is_active FROM users`
	rows, err := u.db.Query(query)
	if err != nil {
		u.log.Error(context.Background(), "error: querying users", slog.Any("error", err))
		return nil, err
	}
	defer rows.Close()

	var users []model.User
	for rows.Next() {
		var user model.User
		if err := rows.Scan(&user.ID, &user.Name, &user.Username, &user.Password, &user.Email, &user.IsActive); err != nil {

			u.log.Error(context.Background(), "error: scanning user row", slog.Any("error", err))
			return nil, err
		}
		users = append(users, user)
	}

	if err := rows.Err(); err != nil {
		u.log.Error(context.Background(), "error: iterating user rows", slog.Any("error", err))
		return nil, err
	}

	return users, nil
}

func (u *userRepository) Create(user *model.User) error {
	query := `INSERT INTO users (id, name, username, password, email, is_active) VALUES ($1, $2, $3, $4, $5, $6)`
	_, err := u.db.Exec(query, user.ID, user.Name, user.Username, user.Password, user.Email, user.IsActive)
	if err != nil {
		u.log.Error(context.Background(), "error: inserting user", slog.Any("error", err))
		return err
	}

	return nil
}

func (u *userRepository) FindById(id string) (*model.User, error) {
	query := `SELECT id, name, username, password, email, is_active FROM users WHERE id = $1`
	row := u.db.QueryRow(query, id)

	var user model.User
	if err := row.Scan(&user.ID, &user.Name, &user.Username, &user.Password, &user.Email, &user.IsActive); err != nil {
		if err == sql.ErrNoRows {
			return nil, nil
		}
		u.log.Error(context.Background(), "error: scanning user row", slog.Any("error", err))
		return nil, err
	}

	return &user, nil
}

func (u *userRepository) Update(user *model.User) error {
	query := `UPDATE users SET name = $1, is_active = $2 WHERE id = $3 RETURNING username, email`
	err := u.db.QueryRow(query, user.Name, user.IsActive, user.ID).Scan(&user.Username, &user.Email)
	if err != nil {
		u.log.Error(context.Background(), "error: updating user", slog.Any("error", err))
		return err
	}

	return nil
}
```

* Ubah file `internal/service/users.go` untuk menambahkan method Update

```go
package service

import (
	"context"
	"fmt"
	"log/slog"
	"workshop/internal/model"
	"workshop/internal/repository"

	"github.com/jacky-htg/go-libs/logger"
	"github.com/jacky-htg/go-libs/uuid7"
	"golang.org/x/crypto/bcrypt"
)

type Users interface {
	List() ([]model.User, error)
	Create(*model.User) error
	FindById(id string) (*model.User, error)
	Update(*model.User) error
}

type users struct {
	log  logger.Logger
	repo repository.UserRepository
}

func NewUsers(repo repository.UserRepository, log logger.Logger) Users {
	return &users{repo: repo, log: log}
}

func (u *users) List() ([]model.User, error) {
	return u.repo.List()
}

func (u *users) Create(user *model.User) error {

	pass, err := bcrypt.GenerateFromPassword([]byte(user.Password), bcrypt.DefaultCost)
	if err != nil {
		u.log.Error(context.Background(), "error generate password", slog.Any("error", err))
		return err
	}

	user.ID = uuid7.New()
	user.Password = string(pass)

	if err := u.repo.Create(user); err != nil {
		return err
	}

	return nil
}

func (u *users) FindById(id string) (*model.User, error) {
	return u.repo.FindById(id)
}

func (u *users) Update(user *model.User) error {
	existUser, err := u.repo.FindById(user.ID)
	if err != nil {
		return err
	}
	if existUser == nil {
		return fmt.Errorf("user not found")
	}
	return u.repo.Update(user)
}
```

* Ubah file `internal/dto/user_request.go` untuk menambahkan struct UserUpdateRequest

```go
package dto

import "workshop/internal/model"

type UserRequest struct {
	Name     string `json:"name"`
	Username string `json:"username"`
	Password string `json:"password"`
	Email    string `json:"email"`
	IsActive bool   `json:"is_active"`
}

func (u *UserRequest) Transform(user *model.User) {
	user.Name = u.Name
	user.Username = u.Username
	user.Password = u.Password
	user.Email = u.Email
	user.IsActive = u.IsActive
}

type UserUpdateRequest struct {
	Name     string `json:"name"`
	IsActive bool   `json:"is_active"`
}

func (u *UserUpdateRequest) Transform(user *model.User) {
	user.Name = u.Name
	user.IsActive = u.IsActive
}
```

* Ubah file `internal/handler/user_handler.go` untuk menambahkan method Update

```go
package handler

import (
	"context"
	"encoding/json"
	"log/slog"
	"net/http"
	"workshop/internal/dto"
	"workshop/internal/model"
	"workshop/internal/service"

	"github.com/jacky-htg/go-libs/logger"
)

type UserHandler interface {
	List(w http.ResponseWriter, r *http.Request)
	Create(w http.ResponseWriter, r *http.Request)
	FindById(w http.ResponseWriter, r *http.Request)
	Update(w http.ResponseWriter, r *http.Request)
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
	users, err := u.service.List()
	if err != nil {
		u.log.Error(context.Background(), "error: listing users", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	var response []dto.UserResponse
	for _, user := range users {
		var ur dto.UserResponse
		ur.Transform(user)
		response = append(response, ur)
	}

	data, err := json.Marshal(response)
	if err != nil {
		u.log.Error(context.Background(), "error: marshaling users to JSON", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	if _, err := w.Write(data); err != nil {
		u.log.Error(context.Background(), "error: writing response", slog.Any("error", err))
	}
}

// Create : http handler for creating a new user
func (u *userHandler) Create(w http.ResponseWriter, r *http.Request) {
	var req dto.UserRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		u.log.Error(context.Background(), "error: decoding user request", slog.Any("error", err))
		http.Error(w, "Bad Request", http.StatusBadRequest)
		return
	}
	user := model.User{}
	req.Transform(&user)
	err := u.service.Create(&user)
	if err != nil {
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	var response dto.UserResponse
	response.Transform(user)

	data, err := json.Marshal(response)
	if err != nil {
		u.log.Error(context.Background(), "error: marshaling user to JSON", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusCreated)
	if _, err := w.Write(data); err != nil {
		u.log.Error(context.Background(), "error: writing response", slog.Any("error", err))
	}
}

// FindById : http handler for finding a user by ID
func (u *userHandler) FindById(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	if id == "" {
		http.Error(w, "Bad Request: missing id parameter", http.StatusBadRequest)
		return
	}

	user, err := u.service.FindById(id)
	if err != nil {
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}
	if user == nil {
		http.Error(w, "Not Found", http.StatusNotFound)
		return
	}

	var response dto.UserResponse
	response.Transform(*user)

	data, err := json.Marshal(response)
	if err != nil {
		u.log.Error(context.Background(), "error: marshaling user to JSON", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	if _, err := w.Write(data); err != nil {
		u.log.Error(context.Background(), "error: writing response", slog.Any("error", err))
	}
}

func (u *userHandler) Update(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	if id == "" {
		http.Error(w, "Bad Request: missing id parameter", http.StatusBadRequest)
		return
	}

	var req dto.UserUpdateRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		u.log.Error(context.Background(), "error: decoding user request", slog.Any("error", err))
		http.Error(w, "Bad Request", http.StatusBadRequest)
		return
	}
	user := model.User{ID: id}
	req.Transform(&user)
	err := u.service.Update(&user)
	if err != nil {
		if err.Error() == "user not found" {
			http.Error(w, "Not Found", http.StatusNotFound)
		} else {
			http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		}
		return
	}

	var response dto.UserResponse
	response.Transform(user)

	data, err := json.Marshal(response)
	if err != nil {
		u.log.Error(context.Background(), "error: marshaling user to JSON", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	if _, err := w.Write(data); err != nil {
		u.log.Error(context.Background(), "error: writing response", slog.Any("error", err))
	}
}
```

* Ubah file `internal/router/api.go` untuk menambahkan routing `PUT /users/{id}`

```go
package router

import (
	"database/sql"
	"net/http"
	"workshop/config"
	"workshop/internal/handler"
	"workshop/internal/repository"
	"workshop/internal/service"

	"github.com/jacky-htg/go-libs/logger"
)

func Api(
	cfg config.Config,
	db *sql.DB,
	log logger.Logger,
) http.Handler {
	mux := http.NewServeMux()

	userRepository := repository.NewUserRepository(db, log)
	userService := service.NewUsers(userRepository, log)
	userHandler := handler.NewUserHandler(userService, log)
	mux.HandleFunc("GET /users", userHandler.List)
	mux.HandleFunc("POST /users", userHandler.Create)
	mux.HandleFunc("GET /users/{id}", userHandler.FindById)
	mux.HandleFunc("PUT /users/{id}", userHandler.Update)

	return mux
}
```

## Delete

* Untuk operasi delete kita menggunakan soft delete, dimana data tidak benar-benar dihapus secara fisik, melainkan hanya diupdate deleted_at-nya.
* Karena soft delete, kita akan merubah repository untuk get list dan Find bya id dengan menambahkan kondisi deleted_at is null
* Untuk response, jika sukses kita akan mengembalikan http status 204 (no content) tanpa ada body payload.
* Ubah file `internal/repository/user_repository.go` untuk menambahkan method delete yang mengupdate method List dan FindById

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
	List() ([]model.User, error)
	Create(*model.User) error
	FindById(id string) (*model.User, error)
	Update(*model.User) error
	Delete(id string) error
}

type userRepository struct {
	db  *sql.DB
	log logger.Logger
}

func NewUserRepository(db *sql.DB, log logger.Logger) UserRepository {
	return &userRepository{db: db, log: log}
}

// List : http handler for returning list of users
func (u *userRepository) List() ([]model.User, error) {
	query := `SELECT id, name, username, password, email, is_active FROM users WHERE deleted_at IS NULL`
	rows, err := u.db.Query(query)
	if err != nil {
		u.log.Error(context.Background(), "error: querying users", slog.Any("error", err))
		return nil, err
	}
	defer rows.Close()

	var users []model.User
	for rows.Next() {
		var user model.User
		if err := rows.Scan(&user.ID, &user.Name, &user.Username, &user.Password, &user.Email, &user.IsActive); err != nil {

			u.log.Error(context.Background(), "error: scanning user row", slog.Any("error", err))
			return nil, err
		}
		users = append(users, user)
	}

	if err := rows.Err(); err != nil {
		u.log.Error(context.Background(), "error: iterating user rows", slog.Any("error", err))
		return nil, err
	}

	return users, nil
}

func (u *userRepository) Create(user *model.User) error {
	query := `INSERT INTO users (id, name, username, password, email, is_active) VALUES ($1, $2, $3, $4, $5, $6)`
	_, err := u.db.Exec(query, user.ID, user.Name, user.Username, user.Password, user.Email, user.IsActive)
	if err != nil {
		u.log.Error(context.Background(), "error: inserting user", slog.Any("error", err))
		return err
	}

	return nil
}

func (u *userRepository) FindById(id string) (*model.User, error) {
	query := `SELECT id, name, username, password, email, is_active FROM users WHERE id = $1 AND deleted_at IS NULL`
	row := u.db.QueryRow(query, id)

	var user model.User
	if err := row.Scan(&user.ID, &user.Name, &user.Username, &user.Password, &user.Email, &user.IsActive); err != nil {
		if err == sql.ErrNoRows {
			return nil, nil
		}
		u.log.Error(context.Background(), "error: scanning user row", slog.Any("error", err))
		return nil, err
	}

	return &user, nil
}

func (u *userRepository) Update(user *model.User) error {
	query := `UPDATE users SET name = $1, is_active = $2 WHERE id = $3 RETURNING username, email`
	err := u.db.QueryRow(query, user.Name, user.IsActive, user.ID).Scan(&user.Username, &user.Email)
	if err != nil {
		u.log.Error(context.Background(), "error: updating user", slog.Any("error", err))
		return err
	}

	return nil
}

func (u *userRepository) Delete(id string) error {
	query := `UPDATE users SET deleted_at = timezone('utc', now()) WHERE id = $1`
	_, err := u.db.Exec(query, id)
	if err != nil {
		u.log.Error(context.Background(), "error: deleting user", slog.Any("error", err))
		return err
	}

	return nil
}
```

* Ubah file `internal/service/users.go` untuk menambahkan method delete

```go
package service

import (
	"context"
	"fmt"
	"log/slog"
	"workshop/internal/model"
	"workshop/internal/repository"

	"github.com/jacky-htg/go-libs/logger"
	"github.com/jacky-htg/go-libs/uuid7"
	"golang.org/x/crypto/bcrypt"
)

type Users interface {
	List() ([]model.User, error)
	Create(*model.User) error
	FindById(id string) (*model.User, error)
	Update(*model.User) error
	Delete(id string) error
}

type users struct {
	log  logger.Logger
	repo repository.UserRepository
}

func NewUsers(repo repository.UserRepository, log logger.Logger) Users {
	return &users{repo: repo, log: log}
}

func (u *users) List() ([]model.User, error) {
	return u.repo.List()
}

func (u *users) Create(user *model.User) error {

	pass, err := bcrypt.GenerateFromPassword([]byte(user.Password), bcrypt.DefaultCost)
	if err != nil {
		u.log.Error(context.Background(), "error generate password", slog.Any("error", err))
		return err
	}

	user.ID = uuid7.New()
	user.Password = string(pass)

	if err := u.repo.Create(user); err != nil {
		return err
	}

	return nil
}

func (u *users) FindById(id string) (*model.User, error) {
	return u.repo.FindById(id)
}

func (u *users) Update(user *model.User) error {
	existUser, err := u.repo.FindById(user.ID)
	if err != nil {
		return err
	}
	if existUser == nil {
		return fmt.Errorf("user not found")
	}
	return u.repo.Update(user)
}

func (u *users) Delete(id string) error {
	existUser, err := u.repo.FindById(id)
	if err != nil {
		return err
	}
	if existUser == nil {
		return fmt.Errorf("user not found")
	}
	return u.repo.Delete(id)
}
```

* Ubah file `internal/handler/user_handler.go` untuk menambahkan method Delete

```go
package handler

import (
	"context"
	"encoding/json"
	"log/slog"
	"net/http"
	"workshop/internal/dto"
	"workshop/internal/model"
	"workshop/internal/service"

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
	users, err := u.service.List()
	if err != nil {
		u.log.Error(context.Background(), "error: listing users", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	var response []dto.UserResponse
	for _, user := range users {
		var ur dto.UserResponse
		ur.Transform(user)
		response = append(response, ur)
	}

	data, err := json.Marshal(response)
	if err != nil {
		u.log.Error(context.Background(), "error: marshaling users to JSON", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	if _, err := w.Write(data); err != nil {
		u.log.Error(context.Background(), "error: writing response", slog.Any("error", err))
	}
}

// Create : http handler for creating a new user
func (u *userHandler) Create(w http.ResponseWriter, r *http.Request) {
	var req dto.UserRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		u.log.Error(context.Background(), "error: decoding user request", slog.Any("error", err))
		http.Error(w, "Bad Request", http.StatusBadRequest)
		return
	}
	user := model.User{}
	req.Transform(&user)
	err := u.service.Create(&user)
	if err != nil {
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	var response dto.UserResponse
	response.Transform(user)

	data, err := json.Marshal(response)
	if err != nil {
		u.log.Error(context.Background(), "error: marshaling user to JSON", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusCreated)
	if _, err := w.Write(data); err != nil {
		u.log.Error(context.Background(), "error: writing response", slog.Any("error", err))
	}
}

// FindById : http handler for finding a user by ID
func (u *userHandler) FindById(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	if id == "" {
		http.Error(w, "Bad Request: missing id parameter", http.StatusBadRequest)
		return
	}

	user, err := u.service.FindById(id)
	if err != nil {
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}
	if user == nil {
		http.Error(w, "Not Found", http.StatusNotFound)
		return
	}

	var response dto.UserResponse
	response.Transform(*user)

	data, err := json.Marshal(response)
	if err != nil {
		u.log.Error(context.Background(), "error: marshaling user to JSON", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	if _, err := w.Write(data); err != nil {
		u.log.Error(context.Background(), "error: writing response", slog.Any("error", err))
	}
}

func (u *userHandler) Update(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	if id == "" {
		http.Error(w, "Bad Request: missing id parameter", http.StatusBadRequest)
		return
	}

	var req dto.UserUpdateRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		u.log.Error(context.Background(), "error: decoding user request", slog.Any("error", err))
		http.Error(w, "Bad Request", http.StatusBadRequest)
		return
	}
	user := model.User{ID: id}
	req.Transform(&user)
	err := u.service.Update(&user)
	if err != nil {
		if err.Error() == "user not found" {
			http.Error(w, "Not Found", http.StatusNotFound)
		} else {
			http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		}
		return
	}

	var response dto.UserResponse
	response.Transform(user)

	data, err := json.Marshal(response)
	if err != nil {
		u.log.Error(context.Background(), "error: marshaling user to JSON", slog.Any("error", err))
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	if _, err := w.Write(data); err != nil {
		u.log.Error(context.Background(), "error: writing response", slog.Any("error", err))
	}
}

func (u *userHandler) Delete(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	if id == "" {
		http.Error(w, "Bad Request: missing id parameter", http.StatusBadRequest)
		return
	}

	err := u.service.Delete(id)
	if err != nil {
		if err.Error() == "user not found" {
			http.Error(w, "Not Found", http.StatusNotFound)
		} else {
			http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		}
		return
	}

	w.WriteHeader(http.StatusNoContent)
}
```

* Ubah file `internal/router/api.go` untuk menambahkan routing `DELETE /users/{id}`

```go
package router

import (
	"database/sql"
	"net/http"
	"workshop/config"
	"workshop/internal/handler"
	"workshop/internal/repository"
	"workshop/internal/service"

	"github.com/jacky-htg/go-libs/logger"
)

func Api(
	cfg config.Config,
	db *sql.DB,
	log logger.Logger,
) http.Handler {
	mux := http.NewServeMux()

	userRepository := repository.NewUserRepository(db, log)
	userService := service.NewUsers(userRepository, log)
	userHandler := handler.NewUserHandler(userService, log)
	mux.HandleFunc("GET /users", userHandler.List)
	mux.HandleFunc("POST /users", userHandler.Create)
	mux.HandleFunc("GET /users/{id}", userHandler.FindById)
	mux.HandleFunc("PUT /users/{id}", userHandler.Update)
	mux.HandleFunc("DELETE /users/{id}", userHandler.Delete)

	return mux
}
```
