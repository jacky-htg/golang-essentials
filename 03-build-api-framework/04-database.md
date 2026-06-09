# Database

API tanpa database hanyalah kumpulan data statis. Di bab ini kita akan menghubungkan API dengan **PostgreSQL** sebagai penyimpanan data permanen. Kita akan menggunakan paket `database/sql` bawaan Go — sederhana, performa tinggi, dan tanpa ORM yang kompleks.

Pembahasan dibagi menjadi tiga bagian:
1. Migration – Membuat struktur tabel
2. Seed – Mengisi data awal
3. ListUsers – Mengambil data dari database (bukan hardcode)

## 4.1 Menyiapkan Database

Pertama, buat database di PostgreSQL:

```sql
CREATE DATABASE workshop;
```

Kemudian buat fungsi koneksi. Sesuaikan user dan password dengan konfigurasi PostgreSQL Anda:

```go
func openDB() (*sql.DB, error) {
    return sql.Open("postgres", "postgres://user:password@localhost:5432/workshop?sslmode=disable")
}
```

**Catatan:** Koneksi string di atas menggunakan format URL. Pastikan menginstal driver PostgreSQL terlebih dahulu:

```bash
go get github.com/lib/pq
```

## 4.2 Migration

Migration adalah cara version-controlled untuk membuat dan mengubah skema database. Kita akan menggunakan library [go-libs/migration](https://github.com/jacky-htg/go-libs/migration) karena cukup sederhana.

### Langkah 1: Membuat Folder dan File Migration

Buat folder `migration/` di root proyek, lalu buat file `1_0001_users.sql` dengan struktur penamaan:

| Prefix | Kegunaan |
|--------|----------|
| `1_` | Create Table |
| `2_` | Alter Table |
| `3_` | Seed Data |

### Langkah 2: Isi File Migration

```sql
-- Enable extension yang diperlukan
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";  -- Untuk pencarian nana

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

### Langkah 3: Integrasi Migration ke main.go

Kita perlu menambahkan kemampuan menjalankan migration dari command line:

```go
func main() {
    db, err := openDB()
    if err != nil {
        log.Fatalf("error: opening database: %s", err)
    }
    defer db.Close()

    flag.Parse()

    // Jika argumen = "migrate", jalankan migration lalu exit
    if len(flag.Args()) > 0 && flag.Arg(0) == "migrate" {
        if err := migration.Migrate(db, "migration"); err != nil {
            log.Fatalf("error: running migrations: %s", err)
        }
        log.Printf("migrations completed successfully")
        return
    }

    // ... lanjut ke server HTTP ...
}
```

Jalankan migration:

```bash
go run main.go migrate
```

## 4.3 Seed Data

Seed adalah proses mengisi data awal ke database. Buat file `3_0001_users.sql` di folder `migration/`:

```sql
INSERT INTO users (id, name, username, password, email, is_active) VALUES
(uuid_generate_v4(), 'John Doe', 'johndoe', 'secret', 'john.doe@example.com', true),
(uuid_generate_v4(), 'Jane Smith', 'janesmith', 'secret', 'jane.smith@example.com', false);
```

**Peringatan:** Password masih dalam bentuk plain text! Di bab selanjutnya kita akan membahas hashing dengan bcrypt.

Jalankan migration lagi untuk mengisi seed:

```bash
go run main.go migrate
```

Perintah yang sama akan mengeksekusi semua file migration yang belum dijalankan (termasuk seed).

## 4.4 Mengambil Data dari Database

Sekarang kita ubah handler `ListUsers` untuk mengambil data langsung dari database, bukan hardcode.

### Membuat Struct dengan Dependency Injection

Pertama, buat struct `Users` yang menerima koneksi database melalui *dependency injection* (DI). DI membuat kode lebih mudah di-test karena kita bisa mengganti dependency kapan saja.

```go
type Users struct {
    Db *sql.DB
}

func NewUsers(db *sql.DB) *Users {
    return &Users{Db: db}
}
```

### Method List yang Terintegrasi Database

```go
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

### Menghubungkan ke Server

Update fungsi main untuk menggunakan `userService.List` sebagai handler:

```go
userService := NewUsers(db)

server := &http.Server{
    Addr:         "0.0.0.0:9000",
    Handler:      http.HandlerFunc(userService.List), // ← berubah
    ReadTimeout:  5 * time.Second,
    WriteTimeout: 5 * time.Second,
}
```

## 4.5 Kode Lengkap main.go

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
	server := &http.Server{
		Addr:         "0.0.0.0:9000",
		Handler:      http.HandlerFunc(userService.List),
		ReadTimeout:  5 * time.Second,
		WriteTimeout: 5 * time.Second,
	}

	serverErrChan := make(chan error, 1)

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

		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer cancel()

		if err := server.Shutdown(ctx); err != nil {
			log.Printf("error during graceful shutdown: %v", err)
			log.Printf("attempting force close due to graceful shutdown failure")

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

## 4.6 Menjalankan dan Menguji

```bash
# Jalankan migration dan seed
go run main.go migrate

# Jalankan server
go run main.go

# Uji endpoint
curl http://localhost:9000/
```

Response akan berisi data dari database PostgreSQL:

```json
[
    {
        "id": "019eab40-97e2-7ea4-9707-210c937ca432",
        "name": "John Doe",
        "username": "johndoe",
        "password": "secret",
        "email": "john.doe@example.com",
        "is_active": true
    },
    {
        "id": "019eab40-97e2-74a2-a6e9-0a64df6d3415",
        "name": "Jane Smith",
        "username": "janesmith",
        "password": "secret",
        "email": "jane.smith@example.com",
        "is_active": false
    }
]
```

## Ringkasan Bab 4

Di bab ini kita telah belajar:
1. Migration – Mengelola skema database dengan file SQL version-controlled
2. Seed – Mengisi data awal untuk development/testing
3. Dependency Injection – Menyuntikkan koneksi database ke handler
4. Database Query – Menggunakan database/sql untuk mengambil data
5. CLI Pattern – Menambahkan perintah migrate tanpa mengganggu server HTTP

Pola penting yang diperkenalkan:
- File migration dengan prefix numerik (urutan eksekusi jelas)
- Struct Users sebagai receiver method (bukan fungsi global)
- Database connection dibuat sekali di main dan di-inject

Yang akan datang:
- ❌ Masih ada password plain text
- ❌ Belum ada validasi input
- ❌ Semua kode dalam satu file (akan dipisah sesuai Clean Architecture)

Pada bab berikutnya, kita akan membahas Clean Architecture — memisahkan kode ke layer-layer yang bertanggung jawab agar framework kita mudah di-maintain dan di-test.


