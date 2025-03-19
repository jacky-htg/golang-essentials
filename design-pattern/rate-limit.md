# Rate Limit Pattern

Rate limiting adalah teknik untuk membatasi jumlah permintaan (requests) dalam periode waktu tertentu guna:
- Mencegah penyalahgunaan API (misalnya DDoS atau brute force).
- Melindungi performa server agar tidak overload.
- Membagi resource secara adil di antara pengguna.

Perbedaan Rate Limit dengan Semaphore:

| Fitur      | Rate Limit 							| Semaphore 													|
| ---------- | ------------------------------------ | ------------------------------------------------------------- |
| Membatasi  | Jumlah request dalam waktu tertentu 	| Jumlah goroutine aktif 										|
| Penerapan  | Berbasis waktu (misal: 10 req/detik) | Berbasis concurrency (misal: 5 goroutine berjalan bersamaan) 	|
| Penggunaan | API rate limiting 					| Kontrol parallelism 											|

## Jenis-Jenis Rate Limiting

1. Fixed Window → Memeriksa jumlah request dalam interval tetap (misal: 10 request per menit).
2. Sliding Window → Menghitung request dalam periode berjalan agar lebih akurat.
3. Token Bucket → Menggunakan token yang diisi secara periodik (misalnya, 10 token per detik, 1 request = 1 token).
4. Leaky Bucket → Request masuk dalam antrian, diproses secara tetap untuk menghindari lonjakan tiba-tiba.

## Implementasi Simple Rate Limit
Berikut adalah implmentasi rate limit sederhana menggunakan token bucket.

```go
package rate_limiter

import (
	"sync"
	"time"
)

// RateLimiter menggunakan Token Bucket
type RateLimiter struct {
	mu          sync.Mutex
	rate        int       // Requests per second
	burst       int       // Maximum burst capacity
	tokens      int       // Available tokens
	lastChecked time.Time // Last refill time
}

// NewRateLimiter membuat RateLimiter baru
func NewRateLimiter(rate, burst int) *RateLimiter {
	return &RateLimiter{
		rate:        rate,
		burst:       burst,
		tokens:      burst,
		lastChecked: time.Now(),
	}
}

// Allow mengecek apakah request bisa diproses
func (rl *RateLimiter) Allow() bool {
	rl.mu.Lock()
	defer rl.mu.Unlock()

	now := time.Now()
	elapsed := now.Sub(rl.lastChecked).Seconds()
	rl.lastChecked = now

	// Tambah token berdasarkan waktu berlalu
	rl.tokens += int(elapsed * float64(rl.rate))
	if rl.tokens > rl.burst {
		rl.tokens = rl.burst
	}

	// Jika masih ada token, izinkan request
	if rl.tokens > 0 {
		rl.tokens--
		return true
	}

	return false
}
```

Di midldleware bisa memanggil paket rate limiter

```go
package middleware

import (
	"log"
	"net/http"
	"myapp/rate_limiter" // Import dari package rate_limiter
)

// RateLimitMiddleware middleware untuk membatasi request
func RateLimitMiddleware(limiter *rate_limiter.RateLimiter) func(http.Handler) http.Handler {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			if !limiter.Allow() {
				log.Println("Too many requests")
				http.Error(w, "Too Many Requests", http.StatusTooManyRequests)
				return
			}
			next.ServeHTTP(w, r)
		})
	}
}
```

```go
package main

import (
	"fmt"
	"myapp/middleware"
	"myapp/rate_limiter"
	"net/http"
	"time"
)

func main() {
	// Rate limiter: 2 request per detik, max burst 5
	limiter := rate_limiter.NewRateLimiter(2, 5)

	// Middleware rate limit
	middleware := middleware.RateLimitMiddleware(limiter)

	// Handler utama
	handler := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		fmt.Fprintf(w, "Request processed at %s\n", time.Now().Format(time.RFC3339))
	})

	// Pasang middleware di server
	http.Handle("/", middleware(handler))

	fmt.Println("Server running on port 8080")
	http.ListenAndServe(":8080", nil)
}
```

## Kesimpulan

✅ Rate Limiting berguna untuk:

- Mencegah abuse/DDoS dengan membatasi jumlah request per waktu tertentu.
- Menjaga performa server agar tidak overload.
- Mengontrol penggunaan API agar lebih adil untuk semua pengguna.

🚀 Gunakan rate limiting jika Anda ingin membatasi jumlah request dalam periode waktu tertentu!