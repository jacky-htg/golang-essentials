# Bab 1: Startup

Setiap aplikasi backend API dimulai dari proses startup — saat server mendengarkan permintaan masuk dari pengguna. Di Go, paket [net/http](https://golang.org/pkg/net/http) menyediakan semua yang kita butuhkan untuk membangun server HTTP tanpa bantuan framework eksternal.

> **📂 Kode Lengkap Bab Ini:**  
> Seluruh kode yang dibahas di bab ini tersedia di GitHub:
> 🔗 [github.com/jacky-htg/workshop/tree/main/01-startup](https://github.com/jacky-htg/workshop/tree/main/01-startup)

## 1.1 Server HTTP Pertama

Cara paling sederhana untuk menjalankan server adalah dengan menggunakan `http.ListenAndServe`. Fungsi ini menerima dua parameter: alamat listen (domain dan port) serta handler yang akan memproses setiap permintaan.

Berikut contoh minimal server yang merespons "Hello World!" di port 9000:

```go
package main

import (
    "fmt"
    "log"
    "net/http"
)

func main() {

    handler := http.HandlerFunc(helloworld)

    if err := http.ListenAndServe("0.0.0.0:9000", handler); err != nil {
        log.Fatalf("error: listening and serving: %s", err)
    }
}

func helloworld(w http.ResponseWriter, r *http.Request) {
    fmt.Fprint(w, "Hello World!")
}
```

Penjelasan kode :
- `http.HandlerFunc(helloworld)` mengkonversi fungsi `helloworld` menjadi tipe `http.Handler`
- `ListenAndServe` memblokir eksekusi program hingga server berhenti atau terjadi error


## 1.2 Konfigurasi Server dengan Struct http.Server

Pada aplikasi nyata, kita biasanya butuh kontrol lebih atas perilaku server, seperti batas waktu baca (read timeout) dan tulis (write timeout). Go menyediakan struct [http.Server](https://golang.org/pkg/net/http/#Server) untuk keperluan ini.

```go
package main

import (
    "fmt"
    "log"
    "net/http"
    "time"
)

func main() {

    server := http.Server{
        Addr:         "0.0.0.0:9000",
        Handler:      http.HandlerFunc(helloworld),
        ReadTimeout:  5 * time.Second,
        WriteTimeout: 5 * time.Second,
    }

    if err := server.ListenAndServe(); err != nil {
        log.Fatalf("error: listening and serving: %s", err)
    }
}

func helloworld(w http.ResponseWriter, r *http.Request) {
    fmt.Fprint(w, "Hello World!")
}
```

Dengan pendekatan ini, kita bisa menambah berbagai parameter seperti MaxHeaderBytes, TLSConfig, atau ConnContext nantinya.

## 1.3 Menjalankan Server Secara Asinkron

Pada aplikasi yang lebih kompleks, proses startup tidak hanya menyalakan server HTTP, tetapi juga menghubungkan database, memuat konfigurasi, atau menjalankan background worker. Jika server berjalan secara blocking, tugas-tugas tersebut tidak akan pernah tereksekusi.

Solusinya adalah menjalankan server di dalam goroutine dan menangkap error yang mungkin terjadi melalui channel:

```go
package main

import (
    "fmt"
    "log"
    "net/http"
    "time"
)

func main() {

    server := http.Server{
        Addr:         "0.0.0.0:9000",
        Handler:      http.HandlerFunc(helloworld),
        ReadTimeout:  5 * time.Second,
        WriteTimeout: 5 * time.Second,
    }

    serverErrors := make(chan error, 1)
    
    go func() {
        log.Println("server listening on", server.Addr)
        serverErrors <- server.ListenAndServe()
    }()

    if err, ok := <-serverErrors; ok && err != nil {
        log.Fatalf("error: listening and serving: %s", err)
    }
}

func helloworld(w http.ResponseWriter, r *http.Request) {
    fmt.Fprint(w, "Hello World!")
}
```

Pola ini menjadi fondasi penting karena:
- Server tidak memblokir `main()`, sehingga kita bisa menambahkan logika inisialisasi lain
- Channel `serverErrors` memungkinkan kita mendeteksi kegagalan startup (misal port sudah digunakan)

## Ringkasan Bab 1

Di bab ini kita telah belajar:

1. Membuat server HTTP minimal dengan http.ListenAndServe
2. Menggunakan http.Server untuk konfigurasi timeout dan parameter lainnya
3. Menjalankan server secara asinkron menggunakan goroutine + channel sebagai fondasi untuk graceful shutdown nantinya

Pada bab berikutnya, kita akan membahas bagaimana mematikan server dengan aman (graceful shutdown) tanpa memutus koneksi aktif.