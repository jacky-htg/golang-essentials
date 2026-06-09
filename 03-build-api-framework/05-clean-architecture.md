# Bab 5: Clean Architecture

Setelah kita memiliki API yang terhubung ke database, sekarang saatnya memikirkan struktur kode. Tanpa arsitektur yang jelas, kode akan sulit di-maintain, sulit di-test, dan sulit dikembangkan oleh tim.

Clean Architecture (diperkenalkan oleh Robert C. Martin) adalah pendekatan yang memisahkan kode ke dalam lapisan-lapisan (layer) berdasarkan tanggung jawabnya.

## 5.1 Tiga Layer Utama

![](../.gitbook/assets/clean-architecture.jpg)


PRESENTATION LAYER (HTTP Handler, Routing, Request/Response DTO) :
- Menerima input dari user
- Memformat output ke user
- TIDAK boleh mengandung logika bisnis

DOMAIN LAYER (Service / UseCase) :
- Logika bisnis aplikasi
- Aturan bisnis & validasi
- Tidak peduli dari mana data berasal

DATA LAYER (Repository, Model) :
- Akses database / API eksternal
- Mapping data dari storage ke struct
- Hanya operasi CRUD sederhana

**Prinsip utama:** Ketergantungan hanya mengarah ke dalam (inward). Layer dalam (Domain) tidak boleh tahu tentang layer luar (Presentation/Data).

## 5.2 Struktur Direktori

Berikut struktur direktori yang akan kita bangun:

```text
workshop/
├── cmd/
│   ├── cli/
│   │   └── main.go          # Perintah CLI (migrate, seed, dll)
│   └── server/
│       └── main.go          # Entry point server HTTP
├── internal/                 # Kode inti (tidak boleh diimport dari luar)
│   ├── dto/                  # Data Transfer Object (response)
│   │   └── user_response.go
│   ├── handler/              # Presentation layer
│   │   └── user_handler.go
│   ├── model/                # Data layer - entity
│   │   └── user.go
│   ├── repository/           # Data layer - akses database
│   │   └── user_repository.go
│   └── service/              # Domain layer - logika bisnis
│       └── users.go
├── migration/                # File SQL migration
│   ├── 1_0001_users.sql
│   └── 3_0001_users.sql
├── pkg/                      # Library publik (bisa diimport proyek lain)
│   └── database/
│       └── postgre.go
├── go.mod
└── go.sum
```

**Catatan tentang folder `internal` dan `pkg`: **
- `internal/` – Kode yang hanya boleh digunakan oleh proyek ini. Go compiler akan melarang import dari luar.
- `pkg/` – Kode yang boleh diimport oleh proyek lain (library publik).

## 5.3 Memisahkan Entry Points (Server vs CLI)

Sebelumnya, kita mencampur logika server HTTP dan migration dalam satu main.go. Sekarang kita pisahkan:

### `cmd/server/main.go` – Entry Point untuk API Server

Menjalankan HTTP server, graceful shutdown, dan injeksi dependency.

### `cmd/cli/main.go` – Entry Point untuk Command Line

Menjalankan perintah administrasi seperti migration.

```go
// cmd/cli/main.go
package main

import (
	"flag"
	"log"
	"workshop/pkg/database"

	"github.com/jacky-htg/go-libs/migration"
	_ "github.com/lib/pq"
)

func main() {

	db, err := database.OpenDB()
	if err != nil {
		log.Fatalf("error: opening database: %s", err)
	}
	defer db.Close()

	flag.Parse()

	if len(flag.Args()) > 0 && flag.Arg(0) == "migrate" {
		if err := migration.Migrate(db, "migration"); err != nil {
			log.Fatalf("error: running migrations: %s", err)
		}
		log.Printf("migrations completed successfully")
		return
	}
}
```
## 5.4 Dependency Injection dengan Interface 

Dependency Injection (DI) memastikan setiap object hanya dibuat sekali (singleton) dan disuntikkan ke komponen yang membutuhkan. Kita sudah mempraktekkannya sebelumnya dengan `NewUsers(db)`.

Sekarang kita tingkatkan dengan interface. Interface mendefinisikan kontrak behavior — apa yang bisa dilakukan, bukan bagaimana cara melakukannya.

```go
type UserRepository interface {
    List() ([]model.User, error)
    FindByID(ctx context.Context, id string) (*model.User, error)
    Create(user *model.User) error
}
```

Konvensi penamaan interface:
- Jika berisi satu behavior → akhiri dengan `-er` (contoh: `Reader`, `Writer`)
- Jika berisi banyak behavior → `PascalCase` (contoh: `UserRepository`)

Mengapa interface penting untuk DI?
- Memungkinkan kita mengganti implementasi (misal: dari PostgreSQL ke MongoDB) tanpa mengubah kode lain
- Memudahkan unit testing dengan mock object


## 5.5 Implementasi Layer per Layer

### Layer Data – Model (`internal/model/user.go`)

Model adalah representasi struktur data dari database. Tidak mengandung tag JSON karena ini murni untuk layer data.

```go
package model

type User struct {
	ID       string
	Name     string
	Username string
	Password string
	Email    string
	IsActive bool
}
```

### Layer Data – Repository (`internal/repository/user_repository.go`)

Repository bertanggung jawab untuk operasi database. Hanya berisi query sederhana — tanpa logika bisnis.

```go
package repository

import (
	"database/sql"
	"log"
	"workshop/internal/model"
)

type UserRepository interface {
	List() ([]model.User, error)
}

type userRepository struct {
	db *sql.DB
}

func NewUserRepository(db *sql.DB) UserRepository {
	return &userRepository{db: db}
}

// List : http handler for returning list of users
func (u *userRepository) List() ([]model.User, error) {
	query := `SELECT id, name, username, password, email, is_active FROM users`
	rows, err := u.db.Query(query)
	if err != nil {
		log.Printf("error: querying users: %s", err)
		return nil, err
	}
	defer rows.Close()

	var users []model.User
	for rows.Next() {
		var user model.User
		if err := rows.Scan(&user.ID, &user.Name, &user.Username, &user.Password, &user.Email, &user.IsActive); err != nil {
			log.Printf("error: scanning user row: %s", err)
			return nil, err
		}
		users = append(users, user)
	}

	if err := rows.Err(); err != nil {
		log.Printf("error: iterating user rows: %s", err)
		return nil, err
	}

	return users, nil
}
```


* Berikut isi dari file `pkg/database/postgre.go`

### Layer Domain – Service (`internal/service/users.go`)

Service berisi logika bisnis. Di contoh sederhana ini, service hanya meneruskan ke repository. Namun nanti di sinilah validasi, perhitungan, dan aturan bisnis lainnya berada.

```go
package service

import (
	"workshop/internal/model"
	"workshop/internal/repository"
)

type Users interface {
	List() ([]model.User, error)
}

type users struct {
	repo repository.UserRepository
}

func NewUsers(repo repository.UserRepository) Users {
	return &users{repo: repo}
}

func (u *users) List() ([]model.User, error) {
	// Logika bisnis bisa ditambahkan di sini
    // Contoh: filter, sorting, validasi, dll.
	return u.repo.List()
}
```

### Layer Presentation – DTO (`internal/dto/user_response.go`)

DTO (Data Transfer Object) adalah representasi data yang dikirim ke client. Tidak semua field dari model harus diekspos — misalnya, field Password tidak boleh dikirim ke response.

```go
package dto

import "workshop/internal/model"

type UserResponse struct {
	ID       string `json:"id"`
	Name     string `json:"name"`
	Username string `json:"username"`
	Email    string `json:"email"`
	IsActive bool   `json:"is_active"`
}

func (u *UserResponse) Transform(user model.User) {
	u.ID = user.ID
	u.Name = user.Name
	u.Username = user.Username
	u.Email = user.Email
	u.IsActive = user.IsActive
}
```

**Perhatikan:** Field Password tidak ada di UserResponse — ini sengaja agar password tidak bocor ke client.

### Layer Presentation – Handler (`internal/handler/user_handler.go`)

Handler menerima HTTP request, memanggil service, lalu mengubah hasil menjadi JSON response.


```go
package handler

import (
	"encoding/json"
	"log"
	"net/http"
	"workshop/internal/dto"
	"workshop/internal/service"
)

type UserHanlder interface {
	List(w http.ResponseWriter, r *http.Request)
}

type userHandler struct {
	service service.Users
}

func NewUserHandler(service service.Users) UserHanlder {
	return &userHandler{service: service}
}

// List : http handler for returning list of users
func (u *userHandler) List(w http.ResponseWriter, r *http.Request) {
	users, err := u.service.List()
	if err != nil {
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
		log.Printf("error: marshaling users to JSON: %s", err)
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	if _, err := w.Write(data); err != nil {
		log.Printf("error: writing response: %s", err)
	}
}
```

### Library Pendukung – Database (`pkg/database/postgre.go`)

Kode ini bisa dijadikan library karena tidak spesifik untuk proyek ini.

```go
package database

import "database/sql"

func OpenDB() (*sql.DB, error) {
	return sql.Open("postgres", "postgres://postgres:1234@localhost:5432/workshop?sslmode=disable")
}
```

### Entry Point Server (`cmd/server/main.go`)

Ini adalah tempat perakitan semua komponen (dependency injection). Urutan inisialisasi: database → repository → service → handler.

```go
package main

import (
	"context"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"
	"workshop/internal/handler"
	"workshop/internal/repository"
	"workshop/internal/service"
	"workshop/pkg/database"

	_ "github.com/lib/pq"
)

func main() {

	db, err := database.OpenDB()
	if err != nil {
		log.Fatalf("error: opening database: %s", err)
	}
	defer db.Close()

	userRepository := repository.NewUserRepository(db)
	userService := service.NewUsers(userRepository)
	userHandler := handler.NewUserHandler(userService)

	// server
	server := &http.Server{
		Addr:         "0.0.0.0:9000",
		Handler:      http.HandlerFunc(userHandler.List),
		ReadTimeout:  5 * time.Second,
		WriteTimeout: 5 * time.Second,
	}

	serverErrChan := make(chan error, 1)

	// start server in a goroutine
	go func() {
		log.Printf("starting server on %s", server.Addr)
		if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			serverErrChan <- fmt.Errorf("error: listening and serving: %s", err)
		}
		close(serverErrChan)
	}()

	shutdownChan := make(chan os.Signal, 1)
	signal.Notify(shutdownChan, os.Interrupt, syscall.SIGTERM)

	select {
	case err, ok := <-serverErrChan:
		if ok && err != nil {
			log.Fatalf("error: server error: %s", err)
		}
	case sig := <-shutdownChan:
		log.Printf("received shutdown signal: %s", sig)

		// Give more time for graceful shutdown
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer cancel()

		// Attempt graceful shutdown
		if err := server.Shutdown(ctx); err != nil {
			log.Printf("error during graceful shutdown: %v", err)
			log.Printf("attempting force close due to graceful shutdown failure")

			// Force close if graceful shutdown fails
			if err := server.Close(); err != nil && err != http.ErrServerClosed {
				log.Printf("error during force close: %v", err)
			}
		} else {
			log.Printf("server gracefully shutdown complete")
		}
	}
}

```

## 5.6 Menjalankan Aplikasi

```bash
# Migration
go run cmd/cli/main.go migrate

# Jalankan server
go run cmd/server/main.go

# Uji endpoint
curl http://localhost:9000/
```

## Ringkasan Bab 5

Di bab ini kita telah belajar:

| Komponen | Folder | Tanggung Jawab |
|----------|--------|----------------|
| **Model** | `internal/model` | Struktur data dari database |
| **Repository** | `internal/repository` | Operasi database (CRUD) |
| **Service** | `internal/service` | Logika bisnis |
| **DTO** | `internal/dto` | Format response ke client |
| **Handler** | `internal/handler` | Menerima request, mengembalikan response |
| **Library** | `pkg` | Kode yang bisa digunakan ulang |
| **Entry point** | `cmd` | Server HTTP dan CLI tools |

Manfaat Clean Architecture yang sudah kita rasakan:
- ✅ Pemisahan tanggung jawab yang jelas
- ✅ Model data tidak terikat dengan format JSON
- ✅ Password tidak bocor ke response (karena dipisah di DTO)
- ✅ Repository bisa diganti tanpa mengubah service/handler
- ✅ CLI dan server berbagi kode yang sama

Yang akan datang:
- ❌ Belum ada konfigurasi (database URL masih hardcoded)
- ❌ Belum ada error handling yang terstruktur
- ❌ Belum ada validasi input

Pada bab berikutnya, kita akan membahas Configuration — bagaimana mengelola konfigurasi aplikasi (database URL, port, timeout) tanpa hardcode.