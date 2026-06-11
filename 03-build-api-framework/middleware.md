# Bab 16: Middleware

Dalam pengembangan web, ada kebutuhan lintas endpoint yang harus dipenuhi: logging, autentikasi, recovery dari panic, timeout, CORS, dan lain-lain. Menulis kode yang sama di setiap handler akan melanggar prinsip DRY (Don't Repeat Yourself).

Middleware adalah solusi elegant: fungsi yang membungkus handler dan dieksekusi sebelum handler utama dipanggil.

## 16.1 Konsep Middleware

Secara matematis, middleware adalah fungsi dengan tipe:

```go
type Middleware func(http.Handler) http.Handler
```


Ilustrasi aliran middleware:

```text
Request → Recovery → Timeout → Auth → Handler → Response
              ↓          ↓        ↓
         (panic)    (timeout)  (unauth)
              ↓          ↓        ↓
            Error ← Error ← Error ←
```

Setiap middleware bisa:
1. Meneruskan request ke handler berikutnya (next.ServeHTTP())
2. Menghentikan request dan langsung mengembalikan response (error)
3. Memodifikasi request/response (menambah header, logging)

## 16.2 Library Middleware

Kita akan menggunakan library sederhana dari go-libs/middleware:

```go
// github.com/jacky-htg/go-libs/middleware
package middleware

import "net/http"

type Middleware func(http.Handler) http.Handler

func Chain(handler http.Handler, middlewares ...Middleware) http.Handler {
	for i := len(middlewares) - 1; i >= 0; i-- {
		handler = middlewares[i](handler)
	}
	return handler
}

type Stack []Middleware

// Method untuk menambah middleware
func (s Stack) With(mw ...Middleware) Stack {
	return append(s, mw...)
}

// Method untuk apply ke handler
func (s Stack) Then(handler http.HandlerFunc) http.Handler {
	if len(s) == 0 {
		return handler
	}
	return Chain(handler, s...)
}
```

## 16.3 Middleware yang Akan Dibangun

| Middleware | Fungsi | Akan diaplikasikan ke |
|------------|--------|-----------------------|
| Recovery | Menangkap panic, mencegah server crash | Semua route |
| Timeout | Membatalkan request yang terlalu lama | Semua route |
| Auth | Memvalidasi token (sederhana dulu) | Private routes |

## 16.4 Recovery Middleware

Panic di Go (misal: index out of range, nil pointer dereference) akan menghentikan program. Middleware ini menangkap panic dan mengembalikan error 500:

```go
// pkg/middleware/recovery_middleware.go
package middleware

import (
	"log/slog"
	"net/http"
	"runtime/debug"
	"workshop/pkg/errors"
	"workshop/pkg/response"

	"github.com/jacky-htg/go-libs/logger"
	lib "github.com/jacky-htg/go-libs/middleware"
)

func Recovery(log logger.Logger) lib.Middleware {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			defer func() {
				if err := recover(); err != nil {
					ctx := r.Context()
					log.Error(ctx, "panic recovered",
						slog.Any("error", err),
						slog.String("stack", string(debug.Stack())))
					response.SetError(ctx, log, w, errors.InternalServerError(), nil)
				}
			}()
			next.ServeHTTP(w, r)
		})
	}
}
```

## 16.5 Timeout Middleware

Middleware ini membungkus request dengan context timeout. Jika handler tidak selesai sebelum timeout, client mendapat 504 Gateway Timeout:

```go
// pkg/middleware/timeout_middleware.go
package middleware

import (
	"context"
	"net/http"
	"time"
	"workshop/pkg/errors"
	"workshop/pkg/response"

	"github.com/jacky-htg/go-libs/logger"
	lib "github.com/jacky-htg/go-libs/middleware"
)

func Timeout(log logger.Logger, timeout time.Duration) lib.Middleware {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			originalCtx := r.Context()
			ctx, cancel := context.WithTimeout(originalCtx, timeout)
			defer cancel()

			done := make(chan struct{})
			go func() {
				next.ServeHTTP(w, r.WithContext(ctx))
				close(done)
			}()

			select {
			case <-done:
				return
			case <-ctx.Done():
				response.SetError(originalCtx, log, w, errors.GatewayTimeout(), nil)
			}
		})
	}
}
```

## 16.6 Auth Middleware (Sederhana)

Untuk sementara, auth middleware hanya memeriksa bahwa token tidak kosong dan panjangnya > 10 karakter. Nanti di bab Token akan diperbaiki dengan JWT:

```go
// pkg/middleware/auth_middleware.go
package middleware

import (
	"database/sql"
	"log/slog"
	"net/http"
	"strings"
	"workshop/pkg/errors"
	"workshop/pkg/response"

	"github.com/jacky-htg/go-libs/logger"
	lib "github.com/jacky-htg/go-libs/middleware"
)

func Auth(db *sql.DB, log logger.Logger) lib.Middleware {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			ctx := r.Context()
			authHeader := r.Header.Get("Authorization")
			if authHeader == "" {
				err := errors.Unauthorized()
				log.Error(ctx, "Unauthorized", slog.Any("error", err))
				response.SetError(ctx, log, w, err, nil)
				return
			}

			token := strings.TrimPrefix(authHeader, "Bearer ")
			if token == authHeader {
				err := errors.Unauthorized("Invalid authorization header")
				log.Error(ctx, "Unauthorized", slog.Any("error", err))
				response.SetError(ctx, log, w, err, nil)
				return
			}

			if len(token) <= 10 {
				err := errors.Unauthorized("Invalid token")
				log.Error(ctx, "Unauthorized", slog.Any("error", err))
				response.SetError(ctx, log, w, err, nil)
				return
			}

			next.ServeHTTP(w, r)
		})
	}
}
```

## 16.7 Menambahkan Konfigurasi Gateway Timeout

Tambahkan GatewayTimeout ke config:

* Kita menggunakan env config baru untuk gateway timeout

```go
// config/config.go
type ServerConfig struct {
    AppPort                 int
    WriteTimeout            time.Duration
    ReadTimeout             time.Duration
    IdleTimeout             time.Duration
    GracefulShutdownTimeout time.Duration
    GatewayTimeout          time.Duration  // ← baru
}

func LoadConfig() (Config, error) {
    // ...
    server := ServerConfig{
        // ... yang sudah ada ...
        GatewayTimeout: env.EnvDuration("SERVER_GATEWAY_TIMEOUT", 5*time.Second),
    }
    // ...
}
```

Update .env:

```env
SERVER_GATEWAY_TIMEOUT=5s
```

## 16.8 Mengaplikasikan Middleware ke Routes

Sekarang kita bagi routes menjadi:
- Public routes – tidak perlu auth (health check)
- Private routes – perlu auth (CRUD users)

```go
package router

import (
	"database/sql"
	"net/http"
	"workshop/config"
	"workshop/internal/handler"
	"workshop/internal/repository"
	"workshop/internal/service"
	mid "workshop/pkg/middleware"
	"workshop/pkg/response"

	"github.com/go-playground/validator/v10"
	"github.com/jacky-htg/go-libs/logger"
	"github.com/jacky-htg/go-libs/middleware"
)

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
	userService := service.NewUsers(log, userRepository)
	userHandler := handler.NewUserHandler(log, validate, userService)

	mux.Handle("GET /health", base.Then(func(w http.ResponseWriter, r *http.Request) {
		response.SetOk(r.Context(), log, w, struct{}{})
	}))

	mux.Handle("GET /users", private.Then(userHandler.List))
	mux.Handle("POST /users", private.Then(userHandler.Create))
	mux.Handle("GET /users/{id}", private.Then(userHandler.FindById))
	mux.Handle("PUT /users/{id}", private.Then(userHandler.Update))
	mux.Handle("DELETE /users/{id}", private.Then(userHandler.Delete))

	return mux
}
```

## 16.9 Perbedaan Handle vs HandleFunc

Perhatikan perbedaan ini:

```go
// Sebelum (tanpa middleware)
mux.HandleFunc("GET /users", userHandler.List)

// Sesudah (dengan middleware)
mux.Handle("GET /users", private.Then(userHandler.List))
```

- HandleFunc – langsung menerima `func(http.ResponseWriter, *http.Request)`
- Handle – menerima `http.Handler` interface, yang merupakan hasil dari `Then()`

## 16.10 Menguji Middleware

### Uji Health Check (Public)

```bash
curl localhost:9000/health
```

Response :

```json
{
    "status": "B1",
    "message": "Success",
    "data": {}
}
```

### Uji Private Route Tanpa Token

```bash
curl 'localhost:9000/users'
```

Response :

```json
{
    "status": "E004",
    "message": "Unauthorized",
    "data": {}
}
```

### Uji Private Route Dengan Token

```bash
curl --location 'localhost:9000/users' \
--header 'Authorization: Bearer my-valid-token'
```

Response :

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
        }
    ]
}
```

## 16.11 Diagram Alur Middleware

```text
Request Masuk
      │
      ▼
┌─────────────────────────────────────────────────────────────┐
│                    Recovery Middleware                      │
│  defer recover() → menangkap panic, return 500 jika panic   │
└─────────────────────────────────────────────────────────────┘
      │
      ▼
┌─────────────────────────────────────────────────────────────┐
│                    Timeout Middleware                       │
│  Context with timeout → jika timeout, return 504            │
└─────────────────────────────────────────────────────────────┘
      │
      ▼
┌─────────────────────────────────────────────────────────────┐
│                     Auth Middleware                         │
│  Cek header Authorization → jika invalid, return 401        │
└─────────────────────────────────────────────────────────────┘
      │
      ▼
┌─────────────────────────────────────────────────────────────┐
│                   Actual Handler                            │
│  userHandler.List / Create / FindById / Update / Delete     │
└─────────────────────────────────────────────────────────────┘
      │
      ▼
Response
```

## 16.12 Middleware yang Akan Datang

| Middleware | Bab | Kegunaan |
|------------|-----|----------|
| Logging/Tracing | OpenTelemetry | Request logging, distributed tracing |
| CORS | Security | Mengizinkan cross-origin request |
| Rate Limiter | Performance | Mencegah abuse |
| Metrics | OpenTelemetry | Mengumpulkan metrics (request count, latency) |

## Ringkasan Bab 16

Di bab ini kita telah belajar:

| Konsep | Implementasi |
|--------|--------------|
| Middleware definition | func(http.Handler) http.Handler |
| Chaining | middleware.Chain() atau Stack.Then() |
| Recovery | Menangkap panic, return 500 |
| Timeout | Context timeout, return 504 |
| Auth | Validasi token (sementara) |
| Public vs Private | Base stack vs Private stack |

Manfaat yang kita peroleh:
- ✅ Kode lintas endpoint tidak berulang (DRY)
- ✅ Server tidak crash saat panic
- ✅ Request yang terlalu lama dibatalkan (504)
- ✅ Proteksi sederhana untuk private routes

Yang akan datang:
- Saat ini auth hanya pengecekan panjang token (insecure)
- Bab selanjutnya: Token (Autentikasi) – implementasi JWT yang aman

