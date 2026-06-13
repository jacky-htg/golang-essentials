# Bab 7: Fatal

Dalam pengembangan aplikasi Go, kita sering melihat log.Fatal digunakan untuk menghentikan program ketika terjadi error. Namun, penggunaan log.Fatal yang tersebar di berbagai tempat dapat membuat kode sulit diuji dan dikelola. Bab ini akan membahas pola penggunaan log.Fatal yang disiplin dan terpusat.

> **📂 Kode Lengkap Bab Ini:**  
> Seluruh kode yang dibahas di bab ini tersedia di GitHub:
>
> 🔗 [github.com/jacky-htg/workshop/tree/main/07-fatal](https://github.com/jacky-htg/workshop/tree/main/07-fatal)

## 7.1 Memahami log.Fatal

Fungsi log.Fatal di Go melakukan dua hal sekaligus:
1. Mencetak pesan error ke log
2. Memanggil `os.Exit(1)` untuk menghentikan program secara paksa

```go
log.Fatal("something went wrong")
// Sama seperti:
// log.Print("something went wrong")
// os.Exit(1)
```

Karakteristik penting:
- `defer` tidak akan dieksekusi setelah `log.Fatal`
- Kode setelah `log.Fatal` tidak akan pernah berjalan
- Tidak ada kesempatan untuk melakukan cleanup (menutup koneksi database, dll)

## 7.2 Kapan Menggunakan log.Fatal

`log.Fatal` sebaiknya hanya digunakan untuk error yang:
- Terjadi di awal program (belum ada resource yang perlu dibersihkan)
- Tidak mungkin dipulihkan (unrecoverable)
- Membuat state program tidak valid untuk melanjutkan eksekusi

Contoh penggunaan yang tepat:
- File konfigurasi tidak ditemukan
- Port server sudah digunakan oleh proses lain
- Koneksi database gagal sama sekali

Lokasi yang tepat untuk log.Fatal:
- `func init()` – inisialisasi package-level variable
- `func main()` – entry point aplikasi

## 7.3 Masalah dengan log.Fatal yang Tersebar

Pada bab-bab sebelumnya, kita memiliki log.Fatal di berbagai tempat:

```go
// Di dalam server goroutine
if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
    serverErrChan <- fmt.Errorf("error: listening and serving: %s", err)
}
// Lalu di select:
case err, ok := <-serverErrChan:
    if ok && err != nil {
        log.Fatalf("error: server error: %s", err)  // ← log.Fatal di luar main?
    }
```

Masalah dengan pendekatan ini:
- Sulit diuji – `log.Fatal` akan menghentikan test
- Cleanup tidak berjalan – `defer db.Close()` tidak terpanggil
- Tidak jelas aliran error – bercampur antara return error dan fatal

## 7.4 Pola: Memisahkan logika dari eksekusi

Solusi yang direkomendasikan adalah memindahkan semua logika ke fungsi run() error, lalu hanya main() yang memanggil log.Fatal jika run() mengembalikan error.

Pola ini memiliki keuntungan:
- Semua error dikembalikan sebagai nilai biasa (`return error`)
- `defer` tetap berjalan dengan benar
- Fungsi `run()` bisa diuji secara unit
- Hanya satu `log.Fatal` di seluruh program (di `main`)

```text
┌─────────────────────────────────────────────────────────┐
│                       func main()                       │
│                                                         │
│   if err := run(); err != nil {                         │
│       log.Fatalf("error: %s", err)  ← SATU-SATUNYA      │
│   }                                                     │
└─────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────┐
│                    func run() error                     │
│                                                         │
│   - Load config (return error jika gagal)               │
│   - Open database (return error jika gagal)             │
│   - Start server (return error jika gagal)              │
│   - Wait for signal (return nil jika normal)            │
└─────────────────────────────────────────────────────────┘
```

## 7.5 Implementasi: CLI

Berikut implementasi pola `run() error` pada `cmd/cli/main.go`:

```go
package main

import (
	"flag"
	"fmt"
	"log"
	"workshop/config"
	"workshop/pkg/database"

	"github.com/jacky-htg/go-libs/migration"
	_ "github.com/lib/pq"
)

func main() {
	if err := run(); err != nil {
		log.Fatalf("error: running application: %s", err)
	}
}

func run() error {

	cfg, err := config.LoadConfig()
	if err != nil {
		return fmt.Errorf("error: loading config: %w", err)
	}

	db, err := database.OpenDB(cfg)
	if err != nil {
		return fmt.Errorf("error: opening database: %w", err)
	}
	defer db.Close()

	flag.Parse()

	if len(flag.Args()) > 0 && flag.Arg(0) == "migrate" {
		if err := migration.Migrate(db, "migration"); err != nil {
			return fmt.Errorf("error: running migrations: %w", err)
		}
		log.Printf("migrations completed successfully")
	}

	return nil
}
```

Perhatikan perubahan:
- `log.Fatalf` dihapus dari dalam `run()`, diganti dengan `return fmt.Errorf(...)`
- Hanya `main()` yang memiliki `log.Fatalf`
- Menggunakan `%w` untuk wrapping error (mempertahankan rantai error)

## 7.6 Implementasi: Server

`cmd/server/main.go` juga mengikuti pola yang sama:

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
	"workshop/config"
	"workshop/internal/handler"
	"workshop/internal/repository"
	"workshop/internal/service"
	"workshop/pkg/database"

	_ "github.com/lib/pq"
)

func main() {
	if err := run(); err != nil {
		log.Fatalf("error: running application: %s", err)
	}
}

func run() error {
	cfg, err := config.LoadConfig()
	if err != nil {
		return fmt.Errorf("error: loading config: %w", err)
	}

	db, err := database.OpenDB(cfg)
	if err != nil {
		return fmt.Errorf("error: opening database: %w", err)
	}
	defer db.Close()

	userRepository := repository.NewUserRepository(db)
	userService := service.NewUsers(userRepository)
	userHandler := handler.NewUserHandler(userService)

	// server
	server := &http.Server{
		Addr:         fmt.Sprintf("0.0.0.0:%d", cfg.Server.AppPort),
		Handler:      http.HandlerFunc(userHandler.List),
		ReadTimeout:  cfg.Server.ReadTimeout,
		WriteTimeout: cfg.Server.WriteTimeout,
	}

	serverErrChan := make(chan error, 1)

	// start server in a goroutine
	go func() {
		log.Printf("starting server on %s", server.Addr)
		if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			serverErrChan <- fmt.Errorf("error: listening and serving: %w", err)
		}
		close(serverErrChan)
	}()

	shutdownChan := make(chan os.Signal, 1)
	signal.Notify(shutdownChan, os.Interrupt, syscall.SIGTERM)

	select {
	case err, ok := <-serverErrChan:
		if ok && err != nil {
			return fmt.Errorf("server error: %w", err)
		}
	case sig := <-shutdownChan:
		log.Printf("received shutdown signal: %s", sig)

		// Give more time for graceful shutdown
		ctx, cancel := context.WithTimeout(context.Background(), cfg.Server.GracefulShutdownTimeout)
		defer cancel()

		// Attempt graceful shutdown
		if err := server.Shutdown(ctx); err != nil {
			log.Printf("error during graceful shutdown: %v", err)
			log.Printf("attempting force close due to graceful shutdown failure")

			// Force close if graceful shutdown fails
			if err := server.Close(); err != nil && err != http.ErrServerClosed {
				return fmt.Errorf("error during force close: %w", err)
			}
		} else {
			log.Printf("server gracefully shutdown complete")
		}
	}

	return nil
}
```

Perhatikan perubahan penting:
- `log.Fatalf` di dalam `select` diubah menjadi `return fmt.Errorf(...)`
- Server error sekarang dikembalikan sebagai nilai error dari `run()`
- `main()` tetap hanya memiliki SATU `log.Fatalf`

## 7.7 Perbandingan Sebelum dan Sesudah

| Aspek | Sebelum | Sesudah |
|-------|---------|---------|
| Jumlah `log.Fatal` | 2+ (tersebar) | 1 (hanya di main()) |
| Error handling | Campuran (return & fatal) | Konsisten (return error) |
| Testability | Sulit (fatal hentikan test) | Mudah (`run()` bisa di-test) |
| Cleanup (`defer`) | Tidak jalan setelah fatal | Jalan selalu |
| Error wrapping | Tidak konsisten | Menggunakan `%w` |

## 7.8 Error Wrapping dengan `%w`

Perhatikan penggunaan `%w` (bukan `%v`) saat membungkus error:

```go
// Sebelum (kehilangan informasi error asli)
return fmt.Errorf("loading config: %s", err)

// Sesudah (mempertahankan rantai error)
return fmt.Errorf("loading config: %w", err)
```

Dengan `%w`, kita bisa menggunakan `errors.Is()` dan `errors.As()` nantinya untuk memeriksa tipe error tertentu.

## Ringkasan Bab 7

Di bab ini kita telah belajar:
1. Apa itu `log.Fatal` – Mencetak log + `os.Exit(1)`
2. Kapan menggunakannya – Hanya di `init()` atau `main()`, untuk error yang tidak bisa dipulihkan
3. Pola `run() error` – Memisahkan logika dari eksekusi
4. Satu `log.Fatal` – Hanya di `main()`, memanggil `run()` dan handle error
5. Error wrapping – Menggunakan `%w` untuk mempertahankan rantai error

Manfaat yang kita peroleh:
- ✅ Semua error ditangani secara konsisten (`return error`)
- ✅ `defer` selalu berjalan (koneksi database tertutup dengan benar)
- ✅ Fungsi `run()` bisa diuji secara unit
- ✅ Aliran kode lebih jelas dan mudah dilacak

Yang akan datang:
- Saat ini `run()` sudah cukup rapi, tapi masih bisa dikelompokkan lagi
- Bab selanjutnya: Bootstrap – mengorganisir inisialisasi aplikasi (config, database, dependency injection) dalam satu tempat yang terstruktur