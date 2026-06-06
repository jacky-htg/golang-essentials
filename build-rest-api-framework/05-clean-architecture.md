# Clean architecture

![](../.gitbook/assets/clean-architecture.jpg)

* Terdiri dari 3 layer: presentation layer, domain layer, dan data layer.
* Presentation layer meliputi : routing, payload request dan payload response.
* Domain layer meliputi : use case yang berisi interaction dan logic
* Data layer meliputi : entity / model 
* Untuk mengadopsi clean architecture, dibuat struktur direktori aplikasi sebagai berikut :

```text
> cmd
    > cli
        > main.go
    > server
        > main.go
> internal
    > dto
        > user_response.go
    > handler
        > user_handler.go
    > model
        > user.go
    > repository
        > user_repository.go
    > service
        > users.go
> migration
    1_0001_users.sql
    3_0001_users.sql
> pkg
    > database
        > postgre.go
> go.mod
> go.sum
```

* Untuk command kita pecah menjadi 2, yaitu command untuk cli dan command untuk server.
* Buat folder pkg yang berisi library/tool/helper. Dalam kontek sekarang baru ada 1 paket yaitu pakat database.
* Folder migrations sudah ada dari bab sebelumnya.
* Folder internal berisi kode-kode project yang kita buat, kode inti (core) meliputi kode untuk layer presentation, domain dan data.
* Layer presentation kita taruh di folder handler, dan dto.
* Layer domain kita taruh di folder service (dan jika dibutuhkan kita buat folder usecase). Dalam kontek sekarang, kita hanya perlu folder service saja. 
* Layer data kita taruh di folder model dan repository.

## Dependency Injection

* Untuk mendukung konsep clean architecture, kita akan melengkapi dengan design pattern: dependency injection
* Dependency injection digunakan untuk memastikan satu object hanya dibuat 1x (singleton) dan disuntikkan kepada paket-paket yang membutuhkan.
* Kita sudah mempraktekkan dependency injection ketika membuat koneksi database. 
* Dependency injection dilakukan dengan membuat struct berisi dependency dan fungsi kontruktor untuk menyuntikkan dependency-nya.

```go
type UserRepository struct {
	db *sql.DB
}

func NewUserRepository(db *sql.DB) UserRepository {
	return &UserRepository{db: db}
}
```

## Interface dan Dependency Injection

* Interface biasa digunakan untuk membuat signature dari sebuah paket. Berisi signature dari behavior yang dimiliki oleh interface tersebut. Memastikan semua implmentor mempunyai behavior yang seragam.
* Interface dan dependency injection juga sangat bermanfaat nanti ketika kita sudah mulai membuat unit test.
* Konvensi penamaan interface biasanya jika sesuai behavior maka diberikan akhiran `er`, jika tidak sesuai behavior nama interface PascalCase dan nama implementor camelCase.

```go
type Writer interface {
}

type Reader interface {
}

type UserRepository interface {
    FindByID(ctx context.Context, id int) (*User, error)
}

type userRepository struct {
}

func (u *userRepository) FindByID(ctx context.Context, id int) (*User, error) {
    return nil, nil
}
```

## Implementasi Dependency Injection dan Clean Architecture

* Kita akan memecah kode di file main.go di bab sebelumnya menjadi 8 file yaitu :

```text
cmd/server/main.go -> berisi kode untuk handling start up dan shutdown 
cmd/cli/main.go -> berisi kode untuk handling console command, yaitu migrate
pkg/database/postgre.go -> berisi kode untuk membuat koneksi database
internal/dto/user_response.go -> dto (data transform object) adalah presentation layer yang menentukan field-field apa saja yang akan ditampilkan, berisi struct UserResponse dan method Transform dari entity model database ke UserResponse
internal/hanlder/user_handler.go -> presentation layer untuk menghandle entry point, dengan menerima request, meneruskan ke layer domain, kemudian hasil dari layer domain ditranform menajdi response melalui dto.
internal/service/users.go -> domain layer yang bertugas untuk mengelola logika bisnis, jika membutuhkan data akan meminta ke layer repository.  
internal/models/user.go -> layer data yang berisi struct User yang mencerminkan struktur database (maupun struktur api third pihak ketiga, struktur file, dll).
internal/repository/user_repository.go -> layer data yang berisi behavior dari data
```

* Berikut isi dari file `pkg/database/postgre.go`

```go
package database

import "database/sql"

func OpenDB() (*sql.DB, error) {
	return sql.Open("postgres", "postgres://postgres:1234@localhost:5432/workshop?sslmode=disable")
}
```

* Berikut isi dari file `cmd/cli/main.go`

```go
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
		if err := migration.Migrate(db, "migrations"); err != nil {
			log.Fatalf("error: running migrations: %s", err)
		}
		log.Printf("migrations completed successfully")
		return
	}
}
```

* Berikut isi dari file `internal/model/user.go`

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

* Berikut adalah isi dari file `internal/repository/user_repository.go`

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

* Berikut isi file internal/service/users.go

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
	return u.repo.List()
}
```

* Berikut isi dari file `internal/dto/user_response.go`

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

* Berikut isi dari file `internal/handler/user_handler.go`

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

* Dan isi dari file `cmd/server/main.go` adalah :

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