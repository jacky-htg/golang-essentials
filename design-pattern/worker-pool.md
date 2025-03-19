# Worker Pool
Worker Pool adalah pola konkurensi di mana sejumlah tetap goroutine (worker) dijalankan untuk menangani tugas dari antrian pekerjaan (job queue). Dengan pendekatan ini, kita dapat menghindari overhead akibat terlalu banyak goroutine yang berjalan secara bersamaan.

## Konsep Utama:
- Job Queue: Tempat di mana pekerjaan ditampung sebelum diproses oleh worker.
- Workers (Goroutines): Sejumlah tetap goroutine yang mengambil dan memproses pekerjaan dari job queue.
- Result Channel (Opsional): Jika pekerjaannya menghasilkan output, hasilnya bisa dikirim melalui channel.

## Kapan Menggunakan Worker Pool?
- Jika ada banyak tugas independen yang bisa dieksekusi secara paralel.
- Jika jumlah goroutine perlu dibatasi untuk menghindari konsumsi resource berlebih.
- Jika ingin meningkatkan efisiensi pemrosesan dengan menghindari overhead pembuatan goroutine yang berlebihan.

## Implementasi Worker Pool di Golang
Berikut contoh implementasi Worker Pool sederhana di Golang:

```go
package main

import (
	"fmt"
	"math/rand"
	"sync"
	"time"
)

// Struktur untuk mewakili tugas (job)
type Job struct {
	ID int
}

// Fungsi worker yang mengambil job dari channel dan memprosesnya
func worker(id int, jobs <-chan Job, wg *sync.WaitGroup) {
	defer wg.Done()
	for job := range jobs {
		fmt.Printf("Worker %d memproses job %d\n", id, job.ID)
		time.Sleep(time.Duration(rand.Intn(1000)) * time.Millisecond) // Simulasi pekerjaan
	}
}

func main() {
	const numWorkers = 3  // Jumlah worker
	const numJobs = 10     // Jumlah pekerjaan

	jobs := make(chan Job, numJobs) // Channel untuk menyimpan jobs
	var wg sync.WaitGroup

	// Memulai worker
	for i := 1; i <= numWorkers; i++ {
		wg.Add(1)
		go worker(i, jobs, &wg)
	}

	// Mengirimkan jobs ke dalam channel
	for j := 1; j <= numJobs; j++ {
		jobs <- Job{ID: j}
	}

	close(jobs) // Menutup channel jobs agar worker tahu tidak ada job baru
	wg.Wait()   // Menunggu semua worker selesai
	fmt.Println("Semua pekerjaan telah selesai!")
}
```

## Penjelasan Kode:

1. Channel jobs digunakan sebagai job queue.
2. Worker (worker function) membaca dari jobs dan memproses pekerjaan.
3. Loop utama membuat numWorkers goroutine untuk worker.
4. Jobs dimasukkan ke dalam channel.
5. Channel jobs ditutup untuk memberi sinyal bahwa tidak ada job baru.
6. WaitGroup digunakan untuk menunggu semua worker menyelesaikan tugasnya.

## Keuntungan Worker Pool
✅ Membatasi jumlah goroutine → Menghindari overhead dari terlalu banyak goroutine.
✅ Efisiensi pemrosesan → Tugas didistribusikan ke worker secara merata.
✅ Lebih scalable → Bisa dengan mudah menyesuaikan jumlah worker.

## Kapan Tidak Menggunakan Worker Pool?

❌ Jika jumlah tugas kecil dan overhead goroutine tidak menjadi masalah.
❌ Jika setiap pekerjaan membutuhkan sumber daya unik dan tidak bisa dibagikan antar worker.

## Best Practise Menentukan Jumlah Jobs dan Worker

Menentukan jumlah jobs dalam Worker Pool sangat bergantung pada beberapa faktor seperti jumlah worker, kapasitas CPU, I/O, dan sifat pekerjaan itu sendiri.

### 1. Berdasarkan Jumlah Worker dan Sifat Pekerjaan

```
Jumlah Jobs ≥ Jumlah Worker
```

Mengapa? Jika jumlah jobs lebih kecil dari jumlah worker, ada worker yang idle (menganggur), yang berarti resource tidak digunakan secara optimal.

Namun, jumlah jobs tidak boleh terlalu besar tanpa mempertimbangkan beban kerja karena bisa menyebabkan bottleneck.

### 2. Berdasarkan Tipe Pekerjaan (CPU-Bound vs. I/O-Bound)

Pekerjaan yang dilakukan dalam worker menentukan jumlah jobs yang ideal.

#### A. CPU-Bound (Butuh Banyak Perhitungan)

- Contoh: Enkripsi, kompresi, machine learning inference, hashing, perhitungan matematis intensif.
- Worker biasanya dibatasi oleh jumlah CPU core.
- Formula Optimal:
```
Jumlah Worker ≈ Jumlah Core CPU
```
atau sedikit lebih besar untuk mengakomodasi overhead switching.
Misalnya:
- Jika CPU memiliki 8 core, maka worker bisa 8-12.
- Jumlah jobs bisa dibuat 2x dari worker untuk memastikan ada tugas yang selalu bisa diambil worker.

#### B. I/O-Bound (Sering Menunggu Respons)
- Contoh: HTTP requests, database queries, file I/O, network calls.
- Karena pekerjaan ini sering menunggu, jumlah worker bisa lebih besar dibanding CPU core.
- Formula Optimal:
```
Jumlah Worker ≈ (Jumlah Core CPU * 2) atau lebih tinggi
```

atau 

```
Jumlah Worker ≈ (Jumlah Concurrent Requests / Waktu Tunggu Rata-rata)
```
Misalnya: Jika sistem menangani banyak API call dengan waktu respons 500ms, dan ingin menangani 1000 request per detik worker bisa sekitar (1000 / 0.5) = 2000.

### 3. Benchmark & Profiling

Cara terbaik menentukan jumlah jobs adalah dengan benchmarking dan profiling.
Gunakan tools seperti:
- pprof (Golang built-in profiler)
- htop (monitor CPU usage)
- wrk (untuk load testing HTTP API)
- Apache JMeter (untuk uji beban)

Langkah Benchmarking:
- Mulai dengan jumlah worker = jumlah core CPU.
- Uji performa dengan jumlah jobs yang berbeda (misal, 1x, 2x, 4x dari worker).
- Pantau CPU, RAM, dan latensi untuk melihat titik optimal.
- Jika worker idle lama, bisa ditambah jobs.
- Jika CPU usage selalu 100% tanpa peningkatan throughput, jobs mungkin terlalu banyak.

### 4. Contoh Implementasi Adaptif
Jika ingin menyesuaikan jumlah worker secara otomatis, kita bisa mendeteksi jumlah core CPU dengan runtime.NumCPU():
```go
package main

import (
	"fmt"
	"runtime"
	"sync"
	"time"
)

func worker(id int, jobs <-chan int, wg *sync.WaitGroup) {
	defer wg.Done()
	for job := range jobs {
		fmt.Printf("Worker %d memproses job %d\n", id, job)
		time.Sleep(500 * time.Millisecond) // Simulasi pekerjaan
	}
}

func main() {
	numCPU := runtime.NumCPU() // Deteksi jumlah core CPU
	numWorkers := numCPU * 2   // Bisa dikalikan 2 untuk I/O-Bound
	numJobs := numWorkers * 2  // Jumlah jobs minimal 2x worker

	jobs := make(chan int, numJobs)
	var wg sync.WaitGroup

	// Memulai worker
	for i := 1; i <= numWorkers; i++ {
		wg.Add(1)
		go worker(i, jobs, &wg)
	}

	// Kirim jobs
	for j := 1; j <= numJobs; j++ {
		jobs <- j
	}

	close(jobs)
	wg.Wait()
	fmt.Println("Semua pekerjaan selesai!")
}
```

### Kesimpulan Best Practice

✅ CPU-Bound → Worker ≈ Jumlah Core CPU
✅ I/O-Bound → Worker bisa lebih banyak (Core CPU * 2 atau lebih)
✅ Jumlah Jobs ≥ Jumlah Worker, tetapi tidak terlalu besar untuk menghindari bottleneck
✅ Gunakan Benchmarking & Profiling untuk menentukan jumlah optimal

## Jebakan Goroutine
Ya, kita sudah mengimplementasikan pattern worker pool untuk mencegah overhead, kita sudah memperkirakan jumlah worker dengan baik. Tapi bagaimana jika ada developer lain (tanpa kordinasi) membuat goroutine juga di fungsi lain? Ini mengakibatkan perhitungan jumlah worker yang kita buat menjadi tidak valid, dan berpotensi tinggi untuk mengalamai overhead. Ini karena jumlah total goroutine bisa melampaui kapasitas optimal, yang dapat menyebabkan beberapa masalah seperti:

1. CPU Starvation

- Jika jumlah goroutine lebih banyak dari jumlah thread OS, CPU harus sering melakukan context switching, yang bisa mengurangi performa daripada meningkatkannya.
- Misalnya, jika ada 1000 goroutine aktif tetapi hanya ada 8 CPU core, maka setiap goroutine mendapat jatah waktu sangat kecil, yang bisa memperlambat eksekusi.

2. Konsumsi Memori Berlebih
- Setiap goroutine membutuhkan stack memory (~2 KB awal, bisa berkembang). Jika jumlahnya terlalu banyak, RAM bisa cepat habis.

3. Deadlock & Goroutine Leaks
- Jika ada goroutine yang tidak dikontrol dengan baik (misalnya, tidak membaca dari channel atau tidak diberi timeout), ini bisa menyebabkan deadlock atau memory leaks.

## Global Worker Pool
Untuk memastikan setiap developer yang terlibat tidak membuat goroutine sendiri yang berpotensi membuat overhead, kita bisa mengimplementasikan global worker pool. Alih-alih membuat goroutine, developer cukup mengirimkan pekerjaan (job) ke worker pool yang sudah ada.

### Pendekatan
📌 Worker Pool sebagai Singleton
- Worker pool dibuat satu kali saat aplikasi berjalan.
- Developer lain cukup mengirimkan pekerjaan ke job queue, tanpa perlu membuat goroutine sendiri.

📌 Menggunakan Channel untuk Job Queue
- Developer cukup mengirimkan job ke channel.
- Pekerjaan akan diproses oleh worker pool yang ada.

📌 Thread-Safe dengan sync.Once
- Gunakan sync.Once untuk memastikan worker pool hanya dibuat satu kali.

### Implementasi 

Berikut adalah contoh implementasi worker pool yang bisa digunakan oleh semua developer tanpa perlu membuat goroutine sendiri.

```go
package workerpool

import (
	"fmt"
	"log"
	"sync"
	"time"
)

// Job represents a task to be processed
type Job struct {
	ID     int
	Payload string
}

// WorkerPool struct
type WorkerPool struct {
	jobQueue   chan Job
	numWorkers int
	once       sync.Once
	wg         sync.WaitGroup
}

var pool *WorkerPool

// NewWorkerPool creates a singleton worker pool
func NewWorkerPool(numWorkers, jobQueueSize int) *WorkerPool {
	if pool == nil {
		pool = &WorkerPool{
			jobQueue:   make(chan Job, jobQueueSize),
			numWorkers: numWorkers,
		}
		pool.startWorkers()
	}
	return pool
}

// startWorkers initializes the worker pool
func (wp *WorkerPool) startWorkers() {
	wp.once.Do(func() {
		log.Println("Starting worker pool with", wp.numWorkers, "workers")
		for i := 0; i < wp.numWorkers; i++ {
			wp.wg.Add(1)
			go wp.worker(i)
		}
	})
}

// worker function processes jobs
func (wp *WorkerPool) worker(workerID int) {
	defer wp.wg.Done()
	for job := range wp.jobQueue {
		log.Printf("Worker %d processing job: %d with payload: %s\n", workerID, job.ID, job.Payload)
		time.Sleep(1 * time.Second) // Simulate processing time
	}
}

// SubmitJob allows developers to add a job to the pool
func (wp *WorkerPool) SubmitJob(job Job) {
	wp.jobQueue <- job
}

// Shutdown gracefully stops the worker pool
func (wp *WorkerPool) Shutdown() {
	close(wp.jobQueue)
	wp.wg.Wait()
	log.Println("Worker pool shut down")
}
```

Berikut adalah contoh cara menggunakan worker pool dalam aplikasi utama (package main).

```go
package main

import (
	"fmt"
	"myapp/workerpool"
	"time"
)

func main() {
	// Inisialisasi worker pool global dengan 5 workers dan queue size 10
	wp := workerpool.NewWorkerPool(5, 10)

	// Developer lain cukup memanggil SubmitJob tanpa membuat goroutine
	for i := 1; i <= 20; i++ {
		job := workerpool.Job{
			ID:      i,
			Payload: fmt.Sprintf("Job data %d", i),
		}
		wp.SubmitJob(job)
	}

	// Tunggu sebentar untuk melihat output
	time.Sleep(5 * time.Second)

	// Graceful shutdown
	wp.Shutdown()
}
```

### Bagaimana Ini Mengatasi Masalah Developer Lain Membuat Goroutine?

✅ Worker Pool Sudah Ada → Developer Tidak Perlu Buat Goroutine Sendiri
- Developer cukup memanggil wp.SubmitJob(job) untuk menambahkan pekerjaan ke queue.
- Semua pekerjaan akan diproses oleh worker pool yang ada, tanpa perlu goroutine tambahan.

✅ Job Queue Menjaga Batasan Beban
- Jika developer lain mengirim terlalu banyak job, worker pool hanya akan memproses sesuai kapasitas queue.

✅ Thread-Safe dan Singleton
- Worker pool dibuat sekali saja menggunakan sync.Once.
- Semua developer berbagi satu worker pool global.

✅ Graceful Shutdown
- Worker pool bisa dihentikan dengan aman menggunakan Shutdown().

## Kesiumpulan

🚀 Dengan implementasi ini:
- Developer tidak perlu membuat goroutine sendiri.
- Semua pekerjaan akan otomatis diproses oleh worker pool.
- Thread-safe dan efisien untuk menangani concurrent jobs.

Ini sudah siap dipakai untuk sistem skala besar seperti gRPC handler, HTTP request handler, atau background job processing! 😃