# Bab 3: JSON

Setelah server bisa menyala dan mati dengan aman, sekarang saatnya membangun isi komunikasi API itu sendiri. Hampir semua API modern menggunakan JSON (JavaScript Object Notation) sebagai format pertukaran data karena ringan, mudah dibaca manusia, dan didukung oleh hampir semua bahasa pemrograman.

Di bab ini kita akan mengubah handler HelloWorld menjadi endpoint API yang sesungguhnya: **mengembalikan daftar pengguna dalam format JSON**.

> **📂 Kode Lengkap Bab Ini:**  
> Seluruh kode yang dibahas di bab ini tersedia di GitHub:
>
> 🔗 [github.com/jacky-htg/workshop/tree/main/03-json](https://github.com/jacky-htg/workshop/tree/main/03-json)

## 3.1 Menyiapkan Proyek

Sebelum mulai, kita buat proyek Go dengan module system:

```bash
go mod init workshop
```

Kita akan membutuhkan library [uuid7](https://github.com/jacky-htg/go-libs/uuid7) untuk menghasilkan ID unik. Tambahkan dependency-nya:

```bash
go get github.com/jacky-htg/go-libs/uuid7
go mod tidy
```

**Struktur proyek:** Saat ini semua kode masih dalam satu file main.go. Seiring berkembangnya framework, kita akan memisahkan ke package-package terpisah.

## 3.2 Mendefinisikan Struct Data

Di Go, JSON direpresentasikan melalui struct tags. Tag `json:"nama_field"` menentukan bagaimana field dalam struct dipetakan ke JSON.

```go
type User struct {
    ID       string `json:"id"`
    Name     string `json:"name"`
    Username string `json:"username"`
    Password string `json:"password"`
    Email    string `json:"email"`
    IsActive bool   `json:"is_active"`
}
```

**Konvensi penamaan:** Di Go kita menggunakan PascalCase untuk field struct (karena bersifat publik), tapi di JSON kita menggunakan snake_case — ini praktik umum dalam API REST.

## 3.3 Membuat Handler JSON Pertama

Handler `ListUsers` akan:
1. Menyiapkan data contoh (biasanya dari database, nanti akan kita bahas)
2. Mengonversi (marshal) data Go ke JSON
3. Mengatur header Content-Type
4. Menulis response ke client

```go
func ListUsers(w http.ResponseWriter, r *http.Request) {
    users := []User{
        {
            ID:       uuid7.New(),
            Name:     "John Doe",
            Username: "johndoe",
            Password: "secret",
            Email:    "john.doe@example.com",
            IsActive: true,
        },
        {
            ID:       uuid7.New(),
            Name:     "Jane Smith",
            Username: "janesmith",
            Password: "secret",
            Email:    "jane.smith@example.com",
            IsActive: false,
        },
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

## 3.4 Kode Lengkap main.go

Berikut kode lengkap yang menggabungkan startup, graceful shutdown, dan JSON response:

```go
package main

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/jacky-htg/go-libs/uuid7"
)

func main() {

	server := &http.Server{
		Addr:         "0.0.0.0:9000",
		Handler:      http.HandlerFunc(ListUsers),
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

type User struct {
	ID       string `json:"id"`
	Name     string `json:"name"`
	Username string `json:"username"`
	Password string `json:"password"`
	Email    string `json:"email"`
	IsActive bool   `json:"is_active"`
}

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
```

## 3.5 Menjalankan dan Menguji

Jalankan server:

```bash
go run main.go
```

Uji endpoint menggunakan `curl`:

```bash
curl http://localhost:9000/
```

Response yang diharapkan:

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

## 3.6 Catatan Penting tentang Password

**Peringatan keamanan:** Dalam contoh di atas, password dikirim dalam bentuk plain text! Ini TIDAK boleh dilakukan di aplikasi nyata. Nanti kita akan membahas:

- Hashing password (bcrypt)
- Tidak mengirim field password dalam response API
- Menggunakan struct tags seperti json:"password,omitempty" atau DTO (Data Transfer Object)

## Ringkasan Bab 3

Di bab ini kita telah belajar:

1. Membuat struct dengan JSON tags untuk memetakan data Go ke JSON
2. Menggunakan json.Marshal untuk mengonversi data menjadi JSON
3. Mengatur Content-Type: application/json di response header
4. Menulis JSON response ke http.ResponseWriter
5. Menambahkan dependency management dengan go mod

Apa yang sudah bisa dilakukan API kita:
- ✅ Menyalakan server dengan konfigurasi timeout
- ✅ Mematikan server secara graceful
- ✅ Mengembalikan response dalam format JSON

Yang masih perlu ditambahkan:
- ❌ Menerima data dari client (request body)
- ❌ Validasi data
- ❌ Menyimpan data ke database
- ❌ Status HTTP yang tepat (200, 400, 404, dsb)

Pada bab berikutnya, kita akan menghubungkan API dengan database agar data tidak lagi statis/hardcoded.