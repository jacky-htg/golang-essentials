# Bab 2: Shutdown

Setelah kita bisa menyalakan server, pertanyaan selanjutnya adalah: bagaimana mematikannya dengan aman?

Dalam lingkungan produksi, server tidak boleh berhenti secara tiba-tiba. Ada permintaan yang sedang diproses, koneksi database yang terbuka, atau task latar belakang yang belum selesai. Mematikan server secara paksa (hard shutdown) dapat menyebabkan:

- Response terputus di tengah jalan (client mendapat error)
- Data tidak tersimpan dengan benar
- State aplikasi menjadi korup

Go menyediakan mekanisme graceful shutdown untuk mengatasi masalah ini.

## 2.1 Mendengarkan Sinyal dari OS

Langkah pertama adalah mengetahui kapan sistem operasi ingin mematikan aplikasi kita. Di Linux/Unix, proses menerima sinyal seperti:

- `SIGINT` – dikirim saat user menekan `Ctrl+C`
- `SIGTERM` – dikirim oleh `kill`, `systemctl stop`, atau `docker stop`

Kita bisa mendengarkan sinyal-sinyal ini menggunakan `signal.Notify`:

```go
shutdown := make(chan os.Signal, 1)
signal.Notify(shutdown, os.Interrupt, syscall.SIGTERM)
```

**Catatan:** Channel harus buffered karena paket signal tidak akan memblokir saat mengirim sinyal.

## 2.2 Menggabungkan Dua Sumber Event dengan `select`

Sekarang aplikasi kita memiliki dua sumber event asinkron:
1. Server error – terjadi jika server gagal berjalan (misal port sudah digunakan)
2. Shutdown signal – terjadi jika OS meminta aplikasi berhenti

Kita menggunakan select untuk menangani keduanya secara bersamaan:

```go
select {
case err, ok := <-serverErrors:
    if ok && err != nil {
        log.Fatalf("error: listening and serving: %s", err)
    }

case <-shutdown:
    // lakukan graceful shutdown
}
```

## 2.3 Implementasi Graceful Shutdown Lengkap

Berikut implementasi lengkap dengan strategi fallback: jika graceful shutdown gagal, kita paksa tutup dengan `server.Close()`.

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

    shutdown := make(chan os.Signal, 1)
    signal.Notify(shutdown, os.Interrupt, syscall.SIGTERM)

    select {
    case err, ok := <-serverErrors:
        if ok && err != nil {
			log.Fatalf("error: listening and serving: %s", err)
		}

    case <-shutdown:
        log.Printf("received shutdown signal: %s", sig)

		// Beri waktu 30 detik untuk menyelesaikan request yang sedang berjalan
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer cancel()

		// Coba graceful shutdown
		if err := server.Shutdown(ctx); err != nil {
			log.Printf("error during graceful shutdown: %v", err)
            log.Printf("attempting force close due to graceful shutdown failure")

			// Paksa tutup jika graceful gagal
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

func helloworld(w http.ResponseWriter, r *http.Request) {
    fmt.Fprint(w, "Hello World!")
}
```

## 2.4 Keterbatasan Graceful Shutdown

Penting untuk memahami bahwa tidak semua skenario bisa ditangani dengan graceful shutdown:

Perbandingan Berbagai Skenario Shutdown:
| Skenario | Graceful Shutdown | `server.Close()` | Data Loss Risk |
|----------|-------------------|------------------|----------------|
| **Listrik mati** | ❌ Tidak jalan | ❌ Tidak jalan | 🔴 Tinggi |
| **Kill -9 (SIGKILL)** | ❌ Tidak jalan | ❌ Tidak jalan | 🔴 Tinggi |
| **Ctrl+C (SIGINT)** | ✅ Jalan (jika di-handle) | Bisa dipanggil | 🟢 Rendah |
| **Kill / systemctl stop (SIGTERM)** | ✅ Jalan (jika di-handle) | Bisa dipanggil | 🟢 Rendah |
| **Docker stop (SIGTERM)** | ✅ Jalan (jika di-handle) | Bisa dipanggil | 🟢 Rendah |

Pesan penting: Tidak ada kode Go yang bisa berjalan saat listrik mati atau proses di-kill -9. Graceful shutdown hanya melindungi dari shutdown normal yang dikirim melalui sinyal OS.

## 2.5 Memahami Dua Metode Penutupan Server

Go menyediakan dua metode berbeda untuk menutup server. Pilih berdasarkan kebutuhan:

### `server.Close()` – Penutupan Paksa

- Menutup listener dan semua koneksi aktif seketika
- Request yang sedang berjalan terputus, client mendapat connection reset
- Non-blocking, langsung return

### `server.Shutdown(ctx)` – Penutupan Bertahap

- Menutup listener → tidak menerima request baru
- Menunggu semua request yang sedang berjalan selesai
- Koneksi idle ditutup dengan normal
- Blocking sampai selesai atau timeout


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

**Praktik terbaik:** Gunakan Shutdown sebagai cara utama, dan simpan Close sebagai fallback jika graceful gagal (seperti pada contoh kode di atas).


Ringkasan Bab 2

Di bab ini kita telah belajar:
1. Mendengarkan sinyal OS (SIGINT, SIGTERM) menggunakan signal.Notify
2. Menggabungkan multiple channel events dengan select
3. Implementasi graceful shutdown dengan server.Shutdown
4. Fallback mekanisme dengan server.Close
5. Memahami keterbatasan graceful shutdown pada skenario ekstrem

Pada bab berikutnya, kita akan membahas bagaimana menangani data dalam format JSON — tulang punggung komunikasi API modern.