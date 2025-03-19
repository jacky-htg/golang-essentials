# Concurrency Pattern
Untuk alasan performance, kita sering mengeksploitasi fitur konkurensi di golang. Ada beberapa pattern yang terkenal terkait konkurensi ini, beberapa diantaranya adalah :


## 1. [Worker Pool](worker-pool.md)

- Membuat sejumlah worker (goroutine) tetap yang mengambil tugas dari sebuah job queue.
- Digunakan untuk membatasi jumlah goroutine agar tidak membebani CPU/memori.

Contoh Kasus: Pemrosesan antrian tugas di backend, seperti pemrosesan gambar atau request API.

## 2. Fan-Out, Fan-In

- Fan-Out: Banyak goroutine dibuat untuk memproses data dari satu sumber.
- Fan-In: Beberapa goroutine mengirimkan hasilnya ke satu channel untuk digabungkan.

Contoh Kasus: Memproses banyak permintaan HTTP secara paralel, lalu menggabungkan hasilnya.

## 3. Publish-Subscribe (Pub-Sub)

- Satu publisher mengirimkan pesan ke banyak subscriber.
- Bisa dilakukan dengan channel atau message broker seperti Redis Pub/Sub atau Kafka.

Contoh Kasus: Notifikasi real-time, event-driven architecture.

## 4. Pipeline

- Data mengalir melalui beberapa tahap pemrosesan, di mana setiap tahap dilakukan oleh goroutine berbeda.
- Setiap tahap beroperasi secara independen dengan channel sebagai perantara.

Contoh Kasus: ETL (Extract, Transform, Load), pemrosesan data bertingkat.

## 5. Future / Promise

- Menggunakan goroutine untuk menjalankan tugas async dan mengembalikan hasilnya melalui channel atau struct yang menampung nilai dan status.

Contoh Kasus: Menjalankan beberapa query database secara paralel dan menunggu hasilnya.

## 6. [Rate Limiting / Token Bucket](rate-limit.md)

- Mengontrol jumlah goroutine atau request dalam periode waktu tertentu untuk mencegah overload.

Contoh Kasus: Membatasi jumlah request API ke layanan eksternal.

## 7. [Semaphore](semaphore.md)

- Menggunakan semaphoric channel untuk membatasi jumlah goroutine yang berjalan bersamaan.

Contoh Kasus: Mengontrol akses ke sumber daya yang terbatas seperti koneksi database.

## 8. Balking Pattern

- Jika suatu goroutine menemukan kondisi tertentu (misalnya, resource sedang dipakai), maka ia membatalkan tugasnya tanpa menunggu.

Contoh Kasus: Cache warming, di mana hanya satu goroutine yang boleh memperbarui cache.

## 9. Single Flight Pattern
- Jika dalam waktu bersamaan ada beberapa permintaan identik yang masuk, maka hanya ada satu permintaan yang diteruskan, yang lainnya akan menunggu. Setelah permintaan yang diteruskan mendapatakan response, maka semua permintaan yang masuk akan menerima response yang sama.
- Banyak digunakan untuk mengelola permintaan ke sebuah proses yang lambat/berat.

Contoh Kasus: request reporting, request ke heavy database, request ke proses yang latency tinggi dan consume banyak resource (memory/cpu), call api third party yang lambat.

## 10. Circuit Breaker

- Jika ada kegagalan berturut-turut, sistem akan berhenti mencoba untuk sementara waktu.
- Bisa dikombinasikan dengan timeout atau retry pattern.

Contoh Kasus: Mencegah request berulang ke layanan eksternal yang sedang down.