# Database

Database yang digunakan dalam materi ini adalah database postgresql. Query-query yang digunakan akan memaksimalkan query native pada paket database/sql bawaan golang. Pertimbangannya, paket database/sql sudah cukup mudah untuk digunakan, dan performancenya sangat baik.

Pembahasan database dibagi dalam 3 bahasan, yaitu: pembuatan migration, seed dan implementasi ListUsers dimana datanya diambil dari database.

* Langkah pertama adalah membuat database. Buka postgresql dan buatlah database : "workshop"
* Kemudian buat koneksi database dengan membuat fungsi openDb\(\). Misalkan user=user dan password=password.

```go
func openDB() (*sql.DB, error) {
	return sql.Open("postgres", "postgres://user:password@localhost:5432/workshop?sslmode=disable")
}
```

## Migration

* Ada banyak library yang mengerjakan proses migration. Kali ini saya akan menggunakan library [go-libs](https://github.com/jacky-htg/go-libs) karena cukup simple.
* Buat folder migration. Kemudian buatlah file 1_0001_users.sql
* Konsensus penamaan file adalah prefix 1_ untuk create tabel, 2_ untuk alter tabel, dan 3_ untuk seed tabel. Kemudian diikuti dengan nomor urut file beserta nama file.
* File  1_0001_users.sql digunakan untuk membuat tabel users, yang berisi query sebagai berikut :

```sql
-- Enable extension yang diperlukan
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";  -- Untuk search name

-- Membuat tabel users
CREATE TABLE IF NOT EXISTS users (
    id          UUID PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    username    VARCHAR(100) NOT NULL,
    password    VARCHAR(255) NOT NULL,
    email       VARCHAR(255) NOT NULL,
    is_active   BOOLEAN NOT NULL DEFAULT true,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now()),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now()),
    deleted_at  TIMESTAMPTZ NULL
);

-- =====================================================
-- INDEX SET (Minimum Viable Index untuk awal project)
-- =====================================================

-- 1. Unique partial index untuk username (wajib, untuk login/auth)
CREATE UNIQUE INDEX idx_users_username_unique 
ON users(username) 
WHERE deleted_at IS NULL;

-- 2. Unique partial index untuk email (wajib, untuk komunikasi)
CREATE UNIQUE INDEX idx_users_email_unique 
ON users(email) 
WHERE deleted_at IS NULL;

-- 3. Index untuk pagination/sorting (sering diperlukan)
CREATE INDEX idx_users_created_at_active 
ON users(created_at DESC) 
WHERE deleted_at IS NULL;

-- 4. Partial index untuk filter is_active (kecil dan murah)
CREATE INDEX idx_users_is_active 
ON users(is_active) 
WHERE deleted_at IS NULL AND is_active = true;

-- 5. Trigram index untuk pencarian name (buat hanya jika fitur search diperlukan)
CREATE INDEX idx_users_name_trgm 
ON users USING gin(name gin_trgm_ops) 
WHERE deleted_at IS NULL;

-- =====================================================
-- TRIGGER untuk auto-update updated_at
-- =====================================================

CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = timezone('utc', now());
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_update_users_updated_at
    BEFORE UPDATE ON users
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- =====================================================
-- KOMENTAR TABEL & KOLOM
-- =====================================================

COMMENT ON TABLE users IS 'Table untuk menyimpan data user dengan soft delete';

COMMENT ON COLUMN users.id IS 'Primary key UUID v7, dibuat di Golang';
COMMENT ON COLUMN users.name IS 'Nama lengkap user';
COMMENT ON COLUMN users.username IS 'Username untuk login, harus unik (hanya untuk yang belum terhapus)';
COMMENT ON COLUMN users.password IS 'Password yang sudah di-hash (bcrypt/argon2)';
COMMENT ON COLUMN users.email IS 'Email user, harus unik (hanya untuk yang belum terhapus)';
COMMENT ON COLUMN users.is_active IS 'Status aktif user (true = aktif, false = non-aktif)';
COMMENT ON COLUMN users.created_at IS 'Waktu pembuatan record (UTC)';
COMMENT ON COLUMN users.updated_at IS 'Waktu terakhir update record (UTC), otomatis terupdate via trigger';
COMMENT ON COLUMN users.deleted_at IS 'Waktu soft delete (NULL = tidak terhapus, terisi = sudah dihapus)';

COMMENT ON INDEX idx_users_username_unique IS 'Menjamin username unik untuk data yang belum dihapus, juga mempercepat query login';
COMMENT ON INDEX idx_users_email_unique IS 'Menjamin email unik untuk data yang belum dihapus, juga mempercepat query by email';
COMMENT ON INDEX idx_users_created_at_active IS 'Mempercepat query dengan sorting created_at DESC untuk data aktif';
COMMENT ON INDEX idx_users_is_active IS 'Mempercepat query filter user aktif (partial index kecil)';
COMMENT ON INDEX idx_users_name_trgm IS 'Mempercepat pencarian name dengan partial match (LIKE) untuk data aktif';
```

* Di file main.go akan dilakukan perubahan agar mampu mendukung dua perintah, yaitu perintah listenAndServe http serta perintah migrate yang jalan di console. Perintah flag.Parse\(\) digunakan untuk menangkap parsing parameter yang dilempar dari console.
* Kemudian bandingkan argumen yang diterima, jika argumen == migrate maka eksekusi migration.Migrate\(\) dan setelah selesai diberi perintah return agar baris kode selanjutnya tidak dieksekusi.
* Selain argumen == migrate, akan diabaikan sehingga baris kode yang dijalankan adalah baris http listenAndServe.
* Karena migration.Migrate\(\) membutuhkan koneksi database, maka di awal fungsi utama akan dilakukan pemanggilan fungsi koneksi database.
* Jangan lupa untuk mengimport paket `"github.com/jacky-htg/go-libs/migration"` dan `_ "github.com/lib/pq"`

```go
package main

import (
	"context"
	"database/sql"
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/jacky-htg/go-libs/migration"
	"github.com/jacky-htg/go-libs/uuid7"
	_ "github.com/lib/pq"
)

func main() {

	db, err := openDB()
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

	// server
	server := &http.Server{
		Addr:         "0.0.0.0:9000",
		Handler:      http.HandlerFunc(ListUsers),
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

type User struct {
	ID       string `json:"id"`
	Name     string `json:"name"`
	Username string `json:"username"`
	Password string `json:"password"`
	Email    string `json:"email"`
	IsActive bool   `json:"is_active"`
}

// ListUsers : http handler for returning list of users
func ListUsers(w http.ResponseWriter, r *http.Request) {
	users := []User{
		{ID: uuid7.New(), Name: "John Doe", Username: "johndoe", Password: "secret", Email: "john.doe@example.com", IsActive: true},
		{ID: uuid7.New(), Name: "Jane Smith", Username: "janesmith", Password: "secret", Email: "jane.smith@example.com", IsActive: false},
	}

	data, err := json.Marshal(users)
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

func openDB() (*sql.DB, error) {
	return sql.Open("postgres", "postgres://postgres:1234@localhost:5432/workshop?sslmode=disable")
}
```

* Jalankan `go run main.go migrate` dan `go run main.go` 

## Seed

* Digunakan untuk dump data users
* Buatlah file 3_0001_users.sql yang berisi :

```sql
INSERT INTO users (id, name, username, password, email, is_active) VALUES
(uuid_generate_v4(), 'John Doe', 'johndoe', 'secret', 'john.doe@example.com', true),
(uuid_generate_v4(), 'Jane Smith', 'janesmith', 'secret', 'jane.smith@example.com', false);
```

* Kembali jalankan `go run main.go migrate`

## ListUsers

* Saat ini isi/data dari ListUsers masih di-hardcode. Kini kita akan mengisinya dengan data dari tabel users.
* Ganti handle ListUsers dengan method Users.List
* Buat type Users dengan Db yang diinject dari fungsi utama \(dependency injection\).

```go
//Users : struct for set Users Dependency Injection
type Users struct {
	Db *sql.DB
}

func NewUsers(db *sql.DB) *Users {
	return &Users{Db: db}
}
```

* Buat method Users.List

```go
// List : http handler for returning list of users
func (u Users) List(w http.ResponseWriter, r *http.Request) {
	query := `SELECT id, name, username, password, email, is_active FROM users`
	rows, err := u.Db.Query(query)
	if err != nil {
		log.Printf("error: querying users: %s", err)
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}
	defer rows.Close()

	var users []User
	for rows.Next() {
		var user User
		if err := rows.Scan(&user.ID, &user.Name, &user.Username, &user.Password, &user.Email, &user.IsActive); err != nil {
			log.Printf("error: scanning user row: %s", err)
			http.Error(w, "Internal Server Error", http.StatusInternalServerError)
			return
		}
		users = append(users, user)
	}

	if err := rows.Err(); err != nil {
		log.Printf("error: iterating user rows: %s", err)
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	data, err := json.Marshal(users)
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

* Di fungsi utama, ubah parameter Handler pada server untuk memanggil Users.List

```go
userService := NewUsers(db)
// server
server := &http.Server{
    Addr:         "0.0.0.0:9000",
    Handler:      http.HandlerFunc(userService.List),
    ReadTimeout:  5 * time.Second,
    WriteTimeout: 5 * time.Second,
}
```

* Berikut hasil akhir dari file main.go

```go
package main

import (
	"context"
	"database/sql"
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/jacky-htg/go-libs/migration"
	_ "github.com/lib/pq"
)

func main() {

	db, err := openDB()
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

	userService := NewUsers(db)
	// server
	server := &http.Server{
		Addr:         "0.0.0.0:9000",
		Handler:      http.HandlerFunc(userService.List),
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

type Users struct {
	Db *sql.DB
}

func NewUsers(db *sql.DB) *Users {
	return &Users{Db: db}
}

type User struct {
	ID       string `json:"id"`
	Name     string `json:"name"`
	Username string `json:"username"`
	Password string `json:"password"`
	Email    string `json:"email"`
	IsActive bool   `json:"is_active"`
}

// List : http handler for returning list of users
func (u Users) List(w http.ResponseWriter, r *http.Request) {
	query := `SELECT id, name, username, password, email, is_active FROM users`
	rows, err := u.Db.Query(query)
	if err != nil {
		log.Printf("error: querying users: %s", err)
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}
	defer rows.Close()

	var users []User
	for rows.Next() {
		var user User
		if err := rows.Scan(&user.ID, &user.Name, &user.Username, &user.Password, &user.Email, &user.IsActive); err != nil {
			log.Printf("error: scanning user row: %s", err)
			http.Error(w, "Internal Server Error", http.StatusInternalServerError)
			return
		}
		users = append(users, user)
	}

	if err := rows.Err(); err != nil {
		log.Printf("error: iterating user rows: %s", err)
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return
	}

	data, err := json.Marshal(users)
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

func openDB() (*sql.DB, error) {
	return sql.Open("postgres", "postgres://postgres:1234@localhost:5432/workshop?sslmode=disable")
}
```

