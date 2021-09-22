# Shutdown

* Gracefull shutdown. Jika server tiba-tiba di-shutdown, kita bisa meminta waktu untuk menyelesaikan proses yang sedang dikerjakan terlebih dahulu.
* Untuk mengetahui apakah server di-shutdown, kita listening sinyal dari OS. Dan menerimanya melalui channel.
* Karena sekarang ada lebih dari satu channel, kita akan mengontrolnya melalui perintah SELECT.

```text
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
    case err := <-serverErrors:
        log.Fatalf("error: listening and serving: %s", err)

    case <-shutdown:
        log.Println("caught signal, shutting down")

        // Jika ada shutdown, meminta tambahan waktu 5 detik untuk menyelesaikan proses yang sedang berjalan.
        const timeout = 5 * time.Second
        ctx, cancel := context.WithTimeout(context.Background(), timeout)
        defer cancel()

        if err := server.Shutdown(ctx); err != nil {
            log.Printf("error: gracefully shutting down server: %s", err)
            if err := server.Close(); err != nil {
                log.Printf("error: closing server: %s", err)
            }
        }
    }

    log.Println("done")
}

// helloworld: basic http handler dengan response string hello world
func helloworld(w http.ResponseWriter, r *http.Request) {
    fmt.Fprint(w, "Hello World!")
}
```

