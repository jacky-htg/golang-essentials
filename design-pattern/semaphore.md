# Semaphore Pattern

Semaphore pattern adalah teknik dalam concurrent programming yang digunakan untuk mengontrol jumlah goroutine atau thread yang berjalan secara bersamaan.

🛠 Cara Kerja:

- Semaphore memiliki batas maksimum (limit) untuk jumlah operasi yang berjalan bersamaan.
- Ketika limit tercapai, goroutine berikutnya harus menunggu sampai ada slot yang tersedia.
- Digunakan untuk mencegah overload atau resource starvation pada sistem.

## Contoh Dasar Implementasi Semaphore di Golang

```go
package main

import (
	"fmt"
	"sync"
	"time"
)

func main() {
	const maxConcurrentJobs = 3 // Batas maksimal goroutine yang boleh berjalan
	semaphore := make(chan struct{}, maxConcurrentJobs)

	var wg sync.WaitGroup
	for i := 1; i <= 10; i++ {
		wg.Add(1)

		// Mengisi slot semaphore sebelum memulai pekerjaan
		semaphore <- struct{}{}

		go func(jobID int) {
			defer wg.Done()
			defer func() { <-semaphore }() // Melepaskan slot semaphore setelah selesai

			fmt.Printf("Processing job %d\n", jobID)
			time.Sleep(2 * time.Second) // Simulasi pekerjaan
		}(i)
	}

	wg.Wait()
	fmt.Println("All jobs completed")
}
```

Penjelasan:

- Membatasi jumlah goroutine aktif (dalam contoh ini, hanya 3 goroutine yang berjalan bersamaan).
- Menunggu slot kosong jika jumlah goroutine yang berjalan sudah mencapai batas.
- Mencegah aplikasi overload dengan terlalu banyak goroutine.

Pada prakteknya, seringkali semaphore digunakan di middleware untuk mengontrol banyaknya request yang bisa dilayani secara bersamaan.

## Implementasi Middleware Semaphore untuk gRPC

```go
package middleware

import (
	"context"

	"google.golang.org/grpc"
	"google.golang.org/grpc/codes"
	"google.golang.org/grpc/status"
)

// Semaphore struct untuk membatasi jumlah request
type Semaphore struct {
	sem chan struct{}
}

// NewSemaphore membuat middleware semaphore
func NewSemaphore(maxConcurrentRequests int) *Semaphore {
	return &Semaphore{
		sem: make(chan struct{}, maxConcurrentRequests), // Buffer menentukan batas maksimal request
	}
}

// UnaryInterceptor membatasi jumlah request secara global
func (s *Semaphore) UnaryInterceptor() grpc.UnaryServerInterceptor {
	return func(
		ctx context.Context,
		req interface{},
		info *grpc.UnaryServerInfo,
		handler grpc.UnaryHandler,
	) (interface{}, error) {
		// Coba memasukkan slot ke semaphore
		select {
		case s.sem <- struct{}{}:
			// Pastikan slot dilepas setelah selesai
			defer func() { <-s.sem }()
		default:
			return nil, status.Error(codes.ResourceExhausted, "Too many concurrent requests")
		}

		// Lanjutkan ke handler utama
		return handler(ctx, req)
	}
}
```

Cara Kerja Middleware gRPC Semaphore

- Membatasi jumlah request yang masuk berdasarkan maxConcurrentRequests.
- Jika slot penuh, request langsung ditolak dengan error ResourceExhausted.
- Menggunakan channel sebagai semaphore untuk tracking request yang berjalan.

## Implementasi Middleware Semaphore untuk REST API (HTTP)

```go
package middleware

import (
	"log"
	"net/http"
)

// Semaphore struct untuk REST API
type Semaphore struct {
	sem chan struct{}
}

// NewSemaphore membuat instance semaphore
func NewSemaphore(maxConcurrentRequests int) *Semaphore {
	return &Semaphore{
		sem: make(chan struct{}, maxConcurrentRequests),
	}
}

// Middleware membatasi jumlah request
func (s *Semaphore) Middleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		select {
		case s.sem <- struct{}{}: // Jika masih ada slot, lanjutkan
			defer func() { <-s.sem }() // Pastikan slot dilepas setelah selesai
		default:
			log.Println("Too many concurrent requests")
			http.Error(w, "Too many concurrent requests", http.StatusTooManyRequests)
			return
		}

		next.ServeHTTP(w, r)
	})
}
```

Cara Menggunakan Middleware di HTTP Server 

```go
package main

import (
	"fmt"
	"myapp/middleware"
	"net/http"
	"time"
)

func main() {
	// Buat middleware semaphore dengan batas 3 request bersamaan
	sem := middleware.NewSemaphore(3)

	// Handler utama
	handler := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		fmt.Fprintf(w, "Processing request...\n")
		time.Sleep(2 * time.Second) // Simulasi proses
		fmt.Fprintf(w, "Request completed!\n")
	})

	// Pasang middleware
	http.Handle("/", sem.Middleware(handler))

	fmt.Println("Server running on port 8080")
	http.ListenAndServe(":8080", nil)
}
```

## Kesimpulan

✅ Semaphore cocok di Middleware

- gRPC: Gunakan UnaryInterceptor untuk batasi concurrent request di gRPC server.
- REST API: Gunakan http.Handler middleware untuk batasi HTTP request di web server.

✅ Mengatasi Overload

- Jika batas tercapai, request langsung ditolak dengan error Too many concurrent requests.

✅ Memastikan Performa Stabil

- Dengan semaphore, server tidak overload meskipun banyak request masuk.

🚀 Gunakan middleware ini untuk melindungi API dari lonjakan traffic dan menjaga stabilitas server!
