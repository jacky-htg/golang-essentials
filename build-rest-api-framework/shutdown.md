# Shutdown

* Gracefull shutdown. Jika server tiba-tiba di-shutdown, kita bisa meminta waktu untuk menyelesaikan proses yang sedang dikerjakan terlebih dahulu.
* Untuk mengetahui apakah server di-shutdown, kita listening sinyal dari OS. Dan menerimanya melalui channel.
* Karena sekarang ada lebih dari satu channel, kita akan mengontrolnya melalui perintah SELECT.

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
)

func main() {

    // parameter server
    server := http.Server{
        Addr:         "0.0.0.0:9000",
        Handler:      http.HandlerFunc(helloworld),
        ReadTimeout:  5 * time.Second,
        WriteTimeout: 5 * time.Second,
    }

    serverErrors := make(chan error, 1)

    // mulai listening server
    go func() {
        log.Println("server listening on", server.Addr)
        serverErrors <- server.ListenAndServe()
    }()

    // Membuat channel untuk mendengarkan sinyal interupsi/terminate dari OS.
    // Menggunakan channel buffered karena paket signal membutuhkannya.
    shutdown := make(chan os.Signal, 1)
    signal.Notify(shutdown, os.Interrupt, syscall.SIGTERM)

    // Mengontrol penerimaan data dari channel,
    // jika ada error saat listenAndServe server maupun ada sinyal shutdown yang diterima
    select {
    case err, ok := <-serverErrors:
        if ok && err != nil {
			log.Fatalf("error: listening and serving: %s", err)
		}

    case <-shutdown:
        log.Printf("received shutdown signal: %s", sig)

		// Give more time for graceful shutdown
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer cancel()

		// Attempt graceful shutdown
		if err := server.Shutdown(ctx); err != nil {
			log.Printf("error during graceful shutdown: %v", err)

			// Force close if graceful shutdown fails
			if err := server.Close(); err != nil && err != http.ErrServerClosed {
				log.Printf("error during force close: %v", err)
			} else {
                log.Printf("server close complete")
            }
		} else {
			log.Printf("server gracefully shutdown complete")
		}
    }

    log.Println("done")
}

// helloworld: basic http handler dengan response string hello world
func helloworld(w http.ResponseWriter, r *http.Request) {
    fmt.Fprint(w, "Hello World!")
}
```

Perbedaan server.Close() vs server.Shutdown()

| Aspek | `server.Close()` | `server.Shutdown(ctx)` |
|-------|------------------|------------------------|
| **Menutup listener** | ✅ Langsung | ✅ Setelah graceful |
| **Koneksi aktif** | ❌ Diputus paksa (reset) | ✅ Ditunggu selesai |
| **Request dalam proses** | ❌ Terputus, client dapat error | ✅ Diberi waktu selesai |
| **Keep-Alive connections** | ❌ Ditutup paksa | ✅ Ditutup setelah idle |
| **HTTP/2 streams** | ❌ Diputus | ✅ Ditunggu selesai |
| **Menerima request baru** | ✅ Langsung ditolak | ✅ Langsung ditolak |
| **Idle connections** | ❌ Diputus paksa | ✅ Ditutup normal |
| **Context support** | ❌ Tidak ada timeout | ✅ Bisa pakai timeout |
| **Error return** | ✅ Selalu return error (biasanya `http.ErrServerClosed`) | ✅ Return error jika timeout atau gagal |
| **Blocking behavior** | ✅ Non-blocking, langsung return | ✅ Blocking sampai semua koneksi selesai atau timeout |
| **Use case** | Force shutdown, testing, atau saat graceful gagal | Production graceful shutdown |
| **Client experience** | 🔴 Connection reset / EOF | 🟢 Mendapat response lengkap |
| **Risk** | ⚠️ Data loss, corrupted state | ✅ Aman untuk data integrity |