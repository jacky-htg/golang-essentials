# Bab 17: Token (Autentikasi)

Setelah memiliki middleware auth sederhana (hanya cek panjang token), sekarang saatnya mengimplementasikan autentikasi yang sesungguhnya menggunakan JWT (JSON Web Token).

> **📂 Kode Lengkap Bab Ini:**  
> Seluruh kode yang dibahas di bab ini tersedia di GitHub:
>
> 🔗 [github.com/jacky-htg/workshop/tree/main/17-token](https://github.com/jacky-htg/workshop/tree/main/17-token)

## 17.1 Mengapa JWT?

| Karakteristik | JWT | Session-based |
|---------------|-----|---------------|
| State | Stateless (tidak perlu session di server) | Stateful (perlu menyimpan session) |
| Scalability | Sangat mudah (horizontal scaling) | Perlu session store bersama (Redis) |
| Mobile friendly | ✅ Ya | ❌ Perlu cookie handling |
| Expiration | Built-in (exp claim) | Perlu implementasi sendiri |

## 17.2 Alur Autentikasi dengan JWT

```text
1. Client mengirim email + password ke /login
                    │
                    ▼
2. Server validasi credential, buat JWT
                    │
                    ▼
3. Server mengembalikan JWT ke client
                    │
                    ▼
4. Client menyimpan token (localStorage / secure cookie)
                    │
                    ▼
5. Client mengirim token di header Authorization: Bearer <token>
                    │
                    ▼
6. Middleware Auth memvalidasi token
                    │
                    ▼
7. Jika valid, request diteruskan ke handler
```

## 17.3 Konfigurasi Token

Tambahkan konfigurasi token di `.env`:

```env
TOKEN_SALT=secret-salt-key-change-in-production
TOKEN_EXP=5
```

| Variable | Deskripsi |
|----------|-----------|
| TOKEN_SALT | Kunci rahasia untuk signing JWT (harus aman, jangan di-commit) |
| TOKEN_EXP | Masa berlaku token dalam jam (24 jam = 1 hari) |

## 17.4 Library Token

Kita akan menggunakan library dari `go-libs/token` yang membungkus `golang-jwt/jwt`:

```go
// github.com/jacky-htg/go-libs/token/jwt.go
package token

import (
	"os"
	"time"

	"github.com/golang-jwt/jwt/v5"
)

// CustomClaims dengan interface{} untuk fleksibilitas tipe data
type CustomClaims struct {
	Data map[string]interface{} `json:"data"`
	jwt.RegisteredClaims
}

var mySigningKey = []byte(os.Getenv("TOKEN_SALT"))

// ValidateToken untuk mengembalikan map[string]interface{}
func ValidateToken(myToken string) (bool, map[string]interface{}) {
	token, err := jwt.ParseWithClaims(myToken, &CustomClaims{}, func(token *jwt.Token) (interface{}, error) {
		return mySigningKey, nil
	})

	if err != nil {
		return false, nil
	}

	claims, ok := token.Claims.(*CustomClaims)
	if !ok {
		return false, nil
	}

	return token.Valid, claims.Data
}

// ClaimToken untuk membuat token dengan data berbagai tipe
func ClaimToken(data map[string]interface{}, expirationHours int) (string, error) {
	if expirationHours <= 0 {
		expirationHours = 5
	}

	claims := CustomClaims{
		Data: data,
		RegisteredClaims: jwt.RegisteredClaims{
			ExpiresAt: jwt.NewNumericDate(time.Now().Add(time.Hour * time.Duration(expirationHours))),
			IssuedAt:  jwt.NewNumericDate(time.Now()),
		},
	}

	token := jwt.NewWithClaims(jwt.SigningMethodHS256, claims)
	return token.SignedString(mySigningKey)
}

// Helper function untuk mengkonversi tipe data
func GetString(claims map[string]interface{}, key string) string {
	if val, ok := claims[key]; ok {
		if str, ok := val.(string); ok {
			return str
		}
	}
	return ""
}

func GetInt(claims map[string]interface{}, key string) int {
	if val, ok := claims[key]; ok {
		switch v := val.(type) {
		case int:
			return v
		case float64:
			return int(v)
		case float32:
			return int(v)
		}
	}
	return 0
}

func GetBool(claims map[string]interface{}, key string) bool {
	if val, ok := claims[key]; ok {
		if b, ok := val.(bool); ok {
			return b
		}
	}
	return false
}

func GetFloat64(claims map[string]interface{}, key string) float64 {
	if val, ok := claims[key]; ok {
		if f, ok := val.(float64); ok {
			return f
		}
	}
	return 0.0
}
```

## 17.5 Update Konfigurasi

Tambahkan TokenConfig ke struct Config:

```go
// config/config.go
type Config struct {
    Server   ServerConfig
    Database DatabaseConfig
    Token    TokenConfig  // ← baru
}

type TokenConfig struct {
    TokenSalt string
    TokenExp  int
}

func LoadConfig() (Config, error) {
    // ...
    tokenConfig := TokenConfig{
        TokenSalt: env.Env("TOKEN_SALT", ""),
        TokenExp:  env.EnvInt("TOKEN_EXP", 24),
    }
    // ...
}
```

## 17.6 Repository: FindByEmail

Kita perlu mencari user berdasarkan email untuk proses login:

```go
// internal/repository/user_repository.go
type UserRepository interface {
    // ... method existing
    FindByEmail(ctx context.Context, email string) (*model.User, error)  // ← baru
}

func (u *userRepository) FindByEmail(ctx context.Context, email string) (*model.User, error) {
    query := `SELECT id, name, username, password, email, is_active 
              FROM users WHERE email = $1 AND deleted_at IS NULL`
    row := u.db.QueryRowContext(ctx, query, email)

    var user model.User
    if err := row.Scan(&user.ID, &user.Name, &user.Username, 
                       &user.Password, &user.Email, &user.IsActive); err != nil {
        if err == sql.ErrNoRows {
            return nil, nil
        }
        u.log.Error(ctx, "error: scanning user row", slog.Any("error", err))
        return nil, err
    }

    return &user, nil
}
```

## 17.7 Service: Auths

Service ini bertanggung jawab untuk logika autentikasi:

```go
// internal/service/auths.go
package service

import (
    "context"
    "workshop/config"
    "workshop/internal/repository"
    "workshop/pkg/errors"

    "github.com/jacky-htg/go-libs/logger"
    "github.com/jacky-htg/go-libs/token"
    "golang.org/x/crypto/bcrypt"
)

type Auths interface {
    Login(ctx context.Context, email, password string) (string, *errors.BusinessError)
}

type auths struct {
    log      logger.Logger
    repo     repository.UserRepository
    cfgToken config.TokenConfig
}

func NewAuths(log logger.Logger, cfgToken config.TokenConfig, repo repository.UserRepository) Auths {
    return &auths{
        log:      log,
        repo:     repo,
        cfgToken: cfgToken,
    }
}

func (a *auths) Login(ctx context.Context, email, password string) (string, *errors.BusinessError) {
    // 1. Cari user berdasarkan email
    user, err := a.repo.FindByEmail(ctx, email)
    if err != nil {
        return "", errors.InternalServerErrorWrap(err, "error finding user")
    }
    if user == nil {
        return "", errors.InvalidInput("Invalid email/password")
    }

    // 2. Verifikasi password dengan bcrypt
    if err := bcrypt.CompareHashAndPassword([]byte(user.Password), []byte(password)); err != nil {
        a.log.Error(ctx, "Invalid password for user", slog.String("email", email))
        return "", errors.InvalidInput("Invalid email/password")
    }

    // 3. Cek apakah user aktif
    if !user.IsActive {
        return "", errors.Forbidden("User account is inactive")
    }

    // 4. Generate JWT token
    myToken, err := token.ClaimToken(map[string]any{
        "email": user.Email,
        "id":    user.ID,
    }, a.cfgToken.TokenExp)

    if err != nil {
        a.log.Error(ctx, "Failed to claim token", slog.Any("error", err))
        return "", errors.InternalServerErrorWrap(err, "failed to generate token")
    }

    return myToken, nil
}
```

## 17.8 DTO untuk Login

```go
// internal/dto/login_request.go
package dto

type LoginRequest struct {
    Username string `json:"username" validate:"required,email"` // username di sini adalah email
    Password string `json:"password" validate:"required"`
}
```

```go
// internal/dto/login_response.go
package dto

type LoginResponse struct {
    Token string `json:"token"`
}

func (l *LoginResponse) Transform(token string) {
    l.Token = token
}
```

## 17.9 Handler: AuthHandler

```go
// internal/handler/auth_handler.go
package handler

import (
    "encoding/json"
    "log/slog"
    "net/http"

    "workshop/internal/dto"
    "workshop/internal/service"
    "workshop/pkg/errors"
    "workshop/pkg/response"
    "workshop/pkg/validation"

    "github.com/go-playground/validator/v10"
    "github.com/jacky-htg/go-libs/logger"
)

type AuthHandler interface {
    Login(w http.ResponseWriter, r *http.Request)
}

type authHandler struct {
    log      logger.Logger
    service  service.Auths
    validate *validator.Validate
}

func NewAuthHandler(log logger.Logger, validate *validator.Validate, service service.Auths) AuthHandler {
    return &authHandler{
        log:      log,
        validate: validate,
        service:  service,
    }
}

func (h *authHandler) Login(w http.ResponseWriter, r *http.Request) {
    ctx := r.Context()

    var req dto.LoginRequest
    if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
        h.log.Error(ctx, "error: decoding login request", slog.Any("error", err))
        response.SetError(ctx, h.log, w, errors.InvalidInputWrap(err), nil)
        return
    }

    if err := h.validate.Struct(req); err != nil {
        h.log.Error(ctx, "error: validating login request", slog.Any("error", err))
        response.SetError(ctx, h.log, w, errors.InvalidInputWrap(err), 
            validation.FormatValidationErrors(err))
        return
    }

    token, err := h.service.Login(ctx, req.Username, req.Password)
    if err != nil {
        response.SetError(ctx, h.log, w, err, nil)
        return
    }

    resp := dto.LoginResponse{Token: token}
    response.SetOk(ctx, h.log, w, resp)
}
```

## 17.10 Update Auth Middleware dengan Validasi JWT

Sekarang middleware auth yang sesungguhnya: memvalidasi JWT dan menyimpan claims ke context:

```go
// pkg/app/ctx.go (type custom untuk context key)
package app

type MyCtx string
```

```go
// pkg/middleware/auth_middleware.go
package middleware

import (
    "context"
    "database/sql"
    "log/slog"
    "net/http"
    "strings"
    "workshop/pkg/app"
    "workshop/pkg/errors"
    "workshop/pkg/response"

    "github.com/jacky-htg/go-libs/logger"
    lib "github.com/jacky-htg/go-libs/middleware"
    "github.com/jacky-htg/go-libs/token"
)

func Auth(db *sql.DB, log logger.Logger) lib.Middleware {
    return func(next http.Handler) http.Handler {
        return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
            ctx := r.Context()
            authHeader := r.Header.Get("Authorization")

            // 1. Cek keberadaan header
            if authHeader == "" {
                response.SetError(ctx, log, w, errors.Unauthorized("Missing authorization header"), nil)
                return
            }

            // 2. Parse Bearer token
            mytoken := strings.TrimPrefix(authHeader, "Bearer ")
            if mytoken == authHeader {
                response.SetError(ctx, log, w, 
                    errors.Unauthorized("Invalid authorization header format, expected Bearer"), nil)
                return
            }

            // 3. Validasi JWT
            isValid, claim := token.ValidateToken(mytoken)
            if !isValid {
                response.SetError(ctx, log, w, errors.Unauthorized("Invalid or expired token"), nil)
                return
            }

            // 4. Simpan claims ke context untuk digunakan handler
            ctx = context.WithValue(ctx, app.MyCtx("email"), token.GetString(claim, "email"))
            ctx = context.WithValue(ctx, app.MyCtx("user_id"), token.GetString(claim, "id"))

            next.ServeHTTP(w, r.WithContext(ctx))
        })
    }
}
```

## 17.11 Update Routing

Tambahkan endpoint /login dan gunakan cfg.Token untuk auth service:

```go
// internal/router/api.go
func Api(
    cfg config.Config,
    db *sql.DB,
    log logger.Logger,
    validate *validator.Validate,
) http.Handler {
    mux := http.NewServeMux()

    base := middleware.Stack{
        mid.Recovery(log),
        mid.Timeout(log, cfg.Server.GatewayTimeout),
    }
    private := base.With(mid.Auth(db, log))

    userRepository := repository.NewUserRepository(db, log)

    // Auth service membutuhkan token config
    authService := service.NewAuths(log, cfg.Token, userRepository)
    userService := service.NewUsers(log, userRepository)

    authHandler := handler.NewAuthHandler(log, validate, authService)
    userHandler := handler.NewUserHandler(log, validate, userService)

    // Public routes
    mux.Handle("GET /health", base.Then(func(w http.ResponseWriter, r *http.Request) {
        response.SetOk(r.Context(), log, w, struct{}{})
    }))
    mux.Handle("POST /login", base.Then(authHandler.Login))  // ← endpoint login

    // Private routes (memerlukan token)
    mux.Handle("GET /users", private.Then(userHandler.List))
    mux.Handle("POST /users", private.Then(userHandler.Create))
    mux.Handle("GET /users/{id}", private.Then(userHandler.FindById))
    mux.Handle("PUT /users/{id}", private.Then(userHandler.Update))
    mux.Handle("DELETE /users/{id}", private.Then(userHandler.Delete))

    return mux
}
```

## 17.12 Testing Autentikasi

### Login dengan credential salah

```bash
curl -X POST localhost:9000/login \
  -H "Content-Type: application/json" \
  -d '{"username":"jet@example.com","password":"wrongpassword"}'
```

Response:

```json
{
    "status": "E001",
    "message": "Invalid email/password",
    "data": {}
}
```

### Login dengan credential benar

```bash
curl -X POST localhost:9000/login \
  -H "Content-Type: application/json" \
  -d '{"username":"jet@example.com","password":"1234"}'
```

Response

```json
{
    "status": "B1",
    "message": "Success",
    "data": {
        "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJkYXRhIjp7ImVtYWlsIjoiamV0QGV4YW1wbGUuY29tIiwiaWQiOiIwMTllOWRjNy0zYWI3LTc4OTktYmZhMC1jNTYzMjY5OGUxYzIifSwiZXhwIjoxNzQ5NjQ0MDAwLCJpYXQiOjE3NDk1NTc2MDB9..."
    }
}
```

### Akses private route dengan token valid

```bash
curl --location 'localhost:9000/users' \
--header 'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJkYXRhIjp7ImVtYWlsIjoiamV0QGV4YW1wbGUuY29tIn0sImV4cCI6MTc4MTIwNjQ5NywiaWF0IjoxNzgxMTg4NDk3fQ.xr3vFDIUlnD8emvINA9zZdvvDmKC6eSeWDvXQG08uCs'
```

```json
{
    "status": "B1",
    "message": "Success",
    "data": [
        {
            "id": "019e9fd4-4cd0-7c7c-a88d-c40138fca6c8",
            "name": "John Doe",
            "username": "jacky",
            "email": "jacky@example.com",
            "is_active": false
        },
        {
            "id": "bc5d7cce-fca7-4392-8871-0ea83f2a101e",
            "name": "Jane Smith",
            "username": "janesmith",
            "email": "jane.smith@example.com",
            "is_active": false
        },
        {
            "id": "019e9dc7-3ab7-7899-bfa0-c5632698e1c2",
            "name": "jet",
            "username": "jet",
            "email": "jet@example.com",
            "is_active": true
        }
    ]
}
```

### Akses private route tanpa token

```bash
curl localhost:9000/users
```

Response

```json
{
    "status": "E004",
    "message": "Missing authorization header",
    "data": {}
}
```

### Akses private route dengan token expired/invalid

```bash
curl localhost:9000/users \
  -H "Authorization: Bearer invalid-token"
```

```json
{
    "status": "E004",
    "message": "Invalid token",
    "data": {}
}
```

## 17.13 Aliran Lengkap Autentikasi

```text
┌──────────┐     POST /login      ┌──────────┐
│  Client  │ ───────────────────▶ │  Server  │
└──────────┘   email + password   └──────────┘
                                         │
                                         ▼
                              ┌─────────────────────┐
                              │ AuthService.Login()  │
                              │ 1. FindByEmail()     │
                              │ 2. bcrypt.Compare()  │
                              │ 3. Check IsActive    │
                              │ 4. Generate JWT      │
                              └─────────────────────┘
                                         │
                                         ▼
┌──────────┐      {token}        ┌──────────┐
│  Client  │ ◀─────────────────── │  Server  │
└──────────┘                      └──────────┘
     │
     │ (simpan token)
     │
     ▼
┌──────────┐   GET /users        ┌──────────┐
│  Client  │ ───────────────────▶ │  Server  │
│          │   Bearer <token>     │          │
└──────────┘                      └──────────┘
                                         │
                                         ▼
                              ┌─────────────────────┐
                              │  Auth Middleware    │
                              │ 1. Validate JWT     │
                              │ 2. Extract claims   │
                              │ 3. Store in context │
                              └─────────────────────┘
                                         │
                                         ▼
                              ┌─────────────────────┐
                              │   UserHandler.List  │
                              │   (dapat mengambil  │
                              │    user dari ctx)   │
                              └─────────────────────┘
```

## Ringkasan Bab 17

Di bab ini kita telah belajar:

| Komponen | File | Fungsi |
|----------|------|--------|
| JWT Library | `go-libs/token` | Generate & validate token |
| Token Config | `config/config.go` | Salt & expiration |
| Auth Service | `service/auths.go` | Login logic |
| Auth Handler | `handler/auth_handler.go` | HTTP endpoint /login |
| Auth Middleware | `middleware/auth_middleware.go` | Validate token, inject to context |

Manfaat yang kita peroleh:
- ✅ Autentikasi stateless (tidak perlu session storage)
- ✅ Password tidak pernah dikirim dalam response
- ✅ Token memiliki masa berlaku (expiration)
- ✅ Claims dapat diakses di handler via context
- ✅ Middleware auth sekarang benar-benar memvalidasi JWT

Yang akan datang:
- Saat ini semua user dengan token valid bisa mengakses semua endpoint
- Bab selanjutnya: Role Based Access Controller (Otorisasi) – membatasi akses berdasarkan role user