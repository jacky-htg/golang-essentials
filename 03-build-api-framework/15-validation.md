# Bab 15: Validation

Salah satu prinsip keamanan paling dasar dalam pengembangan API adalah: jangan pernah percaya input dari client. Setiap data yang masuk harus divalidasi sebelum diproses.

Validasi terdiri dari dua jenis:
1. Input validation – Memeriksa format data (email, panjang string, required, dll)
2. Business validation – Memeriksa aturan bisnis (apakah user sudah exist, stok cukup, dll)

Bab ini akan fokus pada input validation menggunakan library `go-playground/validator/v10`.

> **📂 Kode Lengkap Bab Ini:**  
> Seluruh kode yang dibahas di bab ini tersedia di GitHub:
>
> 🔗 [github.com/jacky-htg/workshop/tree/main/15-validation](https://github.com/jacky-htg/workshop/tree/main/15-validation)

## 15.1 Jenis Validasi yang Dibutuhkan

Untuk endpoint User, kita memiliki aturan validasi berikut:

| Field | Aturan | Pesan Error |
|-------|--------|-------------|
| name | Required, min 3 karakter, max 100 | "Name is required / too short / too long" |
| username | Required, min 3 karakter, max 50 | "Username is required / too short / too long" |
| password | Required, min 10 karakter | "Password is required / too short" |
| email | Required, format email | "Email is required / must be valid" |

## 15.2 Menambahkan Tag Validator ke DTO

Update DTO dengan menambahkan tag validate:

```go
// internal/dto/user_request.go
package dto

import (
	"workshop/internal/model"
)

type UserRequest struct {
	Name     string `json:"name" validate:"required,min=3,max=100"`
	Username string `json:"username" validate:"required,min=3,max=50"`
	Password string `json:"password" validate:"required,min=10"`
	Email    string `json:"email" validate:"required,email"`
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
	Name     string `json:"name"  validate:"required,min=3,max=100"`
	IsActive bool   `json:"is_active"`
}

func (u *UserUpdateRequest) Transform(user *model.User) {
	user.Name = u.Name
	user.IsActive = u.IsActive
}
```

## 15.3 Validator Instance (Singleton via Dependency Injection)

Validator v10 memiliki cache untuk struktur yang divalidasi. Sebaiknya instance-nya dibuat sekali dan di-inject ke handler:

```go
// internal/bootstrap/app.go
package bootstrap

import (
	"database/sql"
	"fmt"
	"workshop/config"
	"workshop/pkg/database"

	"github.com/go-playground/validator/v10"
	"github.com/jacky-htg/go-libs/logger"
	_ "github.com/lib/pq"
)

type App struct {
	Config   config.Config
	Database *sql.DB
	Log      logger.Logger
	Validate *validator.Validate

	Cleanup func()
}

func NewApp() (App, error) {
	cfg, err := config.LoadConfig()
	if err != nil {
		return App{}, fmt.Errorf("error: loading config: %w", err)
	}

	db, err := database.OpenDB(cfg)
	if err != nil {
		return App{}, fmt.Errorf("error: opening database: %w", err)
	}

	log := logger.InitLogger(nil)
	validate := validator.New()

	return App{
		Config:   cfg,
		Database: db,
		Log:      log,
		Validate: validate,
		Cleanup: func() {
			if err := db.Close(); err != nil {
				fmt.Printf("error: closing database: %s\n", err)
			}
		},
	}, nil
}
```

## 15.4 Helper untuk Format Error Validation

Validator mengembalikan error dengan tipe `validator.ValidationErrors` yang berisi banyak field error. Kita buat helper untuk mengubahnya menjadi map yang lebih ramah client:

```go
// pkg/validation/validation.go
package validation

import (
	"strings"

	"github.com/go-playground/validator/v10"
)

func FormatValidationErrors(err error) map[string]string {
	errors := make(map[string]string)

	if validationErrors, ok := err.(validator.ValidationErrors); ok {
		for _, fieldErr := range validationErrors {
			field := fieldErr.Field()
			tag := fieldErr.Tag()

			message := generateValidationMessage(field, tag)
			errors[strings.ToLower(field)] = message
		}
	}

	return errors
}

func generateValidationMessage(field, tag string) string {
	switch tag {
	case "required":
		return field + " is required"
	case "email":
		return field + " must be a valid email address"
	case "min":
		return field + " is too short"
	case "max":
		return field + " is too long"
	default:
		return field + " is invalid"
	}
}
```

## 15.5 Update Response untuk Mendukung Multiple Error

Response perlu menampung multiple error messages. Ubah SetError untuk menerima parameter data any:

```go
// pkg/response/response.go
func SetError(ctx context.Context, log logger.Logger, w http.ResponseWriter, err *errors.BusinessError, data any, message ...string) {
	finalMessage := ""
	if len(message) > 0 && len(message[0]) > 0 {
		finalMessage = message[0]
	}

	if finalMessage == "" && err != nil {
		finalMessage = err.Message
	}

	if data == nil {
		data = struct{}{}
	}
	SetResponse(ctx, log, w, err.HTTPStatus, err.Code, finalMessage, data)
}
```

## 15.6 Implementasi Validasi di Handler

Validasi dilakukan di handler layer (bukan service) karena:
- Validasi format input tidak memerlukan logika bisnis
- Service tetap fokus pada aturan bisnis

```go
// internal/handler/user_handler.go
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
	"workshop/pkg/validation"

	"github.com/go-playground/validator/v10"
	"github.com/jacky-htg/go-libs/logger"
)

type userHandler struct {
	log      logger.Logger
	service  service.Users
	validate *validator.Validate
}

func NewUserHandler(log logger.Logger, validate *validator.Validate, service service.Users) UserHandler {
	return &userHandler{log: log, validate: validate, service: service}
}

// Create : http handler for creating a new user
func (u *userHandler) Create(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	var req dto.UserRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		u.log.Error(ctx, "error: decoding user request", slog.Any("error", err))
		response.SetError(ctx, u.log, w, errors.InvalidInputWrap(err), nil)
		return
	}

	if err := u.validate.Struct(req); err != nil {
		u.log.Error(ctx, "error: decoding user request", slog.Any("error", err))
		response.SetError(ctx, u.log, w, errors.InvalidInputWrap(err), validation.FormatValidationErrors(err))
		return
	}

	user := model.User{}
	req.Transform(&user)
	err := u.service.Create(ctx, &user)
	if err != nil {
		response.SetError(ctx, u.log, w, err, nil)
		return
	}

	var resp dto.UserResponse
	resp.Transform(user)
	response.SetCreated(ctx, u.log, w, resp)
}

func (u *userHandler) Update(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id := r.PathValue("id")
	if id == "" {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Missing id parameter"), nil)
		return
	}

	var req dto.UserUpdateRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		u.log.Error(ctx, "error: decoding user request", slog.Any("error", err))
		response.SetError(ctx, u.log, w, errors.InvalidInputWrap(err), nil)
		return
	}

    if err := u.validate.Struct(req); err != nil {
		u.log.Error(ctx, "error: decoding user request", slog.Any("error", err))
		response.SetError(ctx, u.log, w, errors.InvalidInputWrap(err), validation.FormatValidationErrors(err))
		return
	}

	user := model.User{ID: id}
	req.Transform(&user)
	err := u.service.Update(ctx, &user)
	if err != nil {
		response.SetError(ctx, u.log, w, err, nil)
		return
	}

	var resp dto.UserResponse
	resp.Transform(user)

	response.SetOk(ctx, u.log, w, resp)
}
```

## 15.7 Update Routing untuk Inject Validator

```go
package router

import (
	"database/sql"
	"net/http"
	"workshop/config"
	"workshop/internal/handler"
	"workshop/internal/repository"
	"workshop/internal/service"

	"github.com/go-playground/validator/v10"
	"github.com/jacky-htg/go-libs/logger"
)

func Api(
	cfg config.Config,
	db *sql.DB,
	log logger.Logger,
	validate *validator.Validate,
) http.Handler {
	mux := http.NewServeMux()

	userRepository := repository.NewUserRepository(db, log)
	userService := service.NewUsers(log, userRepository)
	userHandler := handler.NewUserHandler(log, validate, userService)
	mux.HandleFunc("GET /users", userHandler.List)
	mux.HandleFunc("POST /users", userHandler.Create)
	mux.HandleFunc("GET /users/{id}", userHandler.FindById)
	mux.HandleFunc("PUT /users/{id}", userHandler.Update)
	mux.HandleFunc("DELETE /users/{id}", userHandler.Delete)

	return mux
}
```

## 15.8 Update Server Main

```go
func run() error {
	app, err := bootstrap.NewApp()
	if err != nil {
		return fmt.Errorf("error: initializing app: %w", err)
	}
	defer app.Cleanup()

	server := &http.Server{
		Addr:         fmt.Sprintf("0.0.0.0:%d", app.Config.Server.AppPort),
		Handler:      router.Api(app.Config, app.Database, app.Log, app.Validate),
		ReadTimeout:  app.Config.Server.ReadTimeout,
		WriteTimeout: app.Config.Server.WriteTimeout,
	}
    // ... kode lainnya tetap sama
}
```

## 15.9 Contoh Response Validasi Error

Ketika client mengirim request dengan data tidak valid:

### Request (password terlalu pendek):

```bash
curl --location 'localhost:9000/users' \
--header 'Content-Type: application/json' \
--data-raw '{
    "name": "jacky",
    "username": "jacky",
    "email": "jacky@example.com",
    "password": "1234",
    "is_active": false
}'
```

Response :

```json
{
    "status": "E001",
    "message": "Invalid input",
    "data": {
        "password": "Password is too short"
    }
}
```

Contoh error multiple field :

```json
{
    "status": "E001",
    "message": "Invalid input",
    "data": {
        "name": "Name is too short",
        "username": "Username is too short",
        "password": "Password is too short",
        "email": "Email must be a valid email address"
    }
}
```

## 15.10 Ringkasan Validasi Berdasarkan Layer

| Layer | Jenis Validasi | Contoh |
|-------|----------------|--------|
| Handler | Input validation | Required, min, max, email format | 
| Service | Business validation | User already exists, stock cukup, status aktif |
| Repository | Data integrity | Tidak ada validasi di sini |

## Ringkasan Bab 15

Di bab ini kita telah belajar:

| Konsep | Implementasi |
|--------|--------------|
| Validation library | go-playground/validator/v10 |
| Validation tags | validate:"required,min=3,email" |
| Single validator instance | Di bootstrap, di-inject ke handler |
| Error formatter | FormatValidationErrors() untuk multiple errors |
| Response enhancement | SetError sekarang support data any untuk detail error |

Manfaat yang kita peroleh:
- ✅ Input terjamin valid sebelum diproses
- ✅ Client mendapat feedback spesifik field mana yang error
- ✅ Multiple error dalam satu response
- ✅ Separation of concerns: handler urus format, service urus logika

Yang akan datang:
- Saat ini belum ada autentikasi
- Bab selanjutnya: Middleware – membangun pipeline untuk logging, auth, recovery, dll