# Future / Promise Pattern

Future/Promise adalah pola pemrograman yang digunakan untuk menangani operasi asinkron dengan cara yang lebih terstruktur. Konsep ini mirip dengan async/await di JavaScript, di mana kita bisa menjalankan tugas secara non-blokir (non-blocking) dan mendapatkan hasilnya nanti setelah tugas selesai.

## Perbedaan Future/Promise dengan Goroutine & Channel di Go

Di JavaScript, Promise atau async/await digunakan untuk menangani operasi asinkron dengan mudah. Sementara itu, di Go, tidak ada Promise secara langsung, tetapi konsep Future bisa diimplementasikan menggunakan goroutine dan channel.

| Fitur          | Promise (JS)          | Future (Go - Custom)           |
| -------------- | --------------------- | ------------------------------ |
| Cara Kerja     | then(), await	     | Channel atau sync.WaitGroup    |
| Eksekusi	     | Non-blocking	         | Non-blocking                   |
| Error Handling | catch() / try...catch | select { case <- errChan }     |
| Library Bawaan | Ya (Promise)	         | Tidak ada, harus dibuat manual |

## Implementasi Future di Go (Mirip dengan Promise di JavaScript)

```go
package main

import (
	"fmt"
	"time"
)

// Future struct untuk menyimpan hasil async task
type Future struct {
	result chan string
}

// AsyncFunction menjalankan tugas secara asinkron dan mengembalikan Future
func AsyncFunction() *Future {
	f := &Future{result: make(chan string, 1)}

	go func() {
		time.Sleep(2 * time.Second) // Simulasi operasi yang butuh waktu lama
		f.result <- "Hasil dari Future!"
	}()

	return f
}

// Get akan mengambil hasil dari Future (mirip dengan await)
func (f *Future) Get() string {
	return <-f.result
}

func main() {
	fmt.Println("Mulai tugas...")
	future := AsyncFunction() // Memulai tugas async tanpa blocking

	// Kita bisa melakukan hal lain di sini sementara tugas async berjalan
	fmt.Println("Melakukan tugas lain...")

	// Ambil hasil dari Future (mirip await)
	result := future.Get()
	fmt.Println("Hasil Future:", result)
}
```

Output:

```
Mulai tugas...
Melakukan tugas lain...
(Hasil muncul setelah 2 detik)
Hasil Future: Hasil dari Future!
```

✅ Tidak blocking, tetap bisa menjalankan kode lain.

## Future dengan Error Handling (Mirip try...catch di Promise)

```go
package main

import (
	"errors"
	"fmt"
	"time"
)

// Future struct untuk menyimpan hasil dan error dari async task
type Future struct {
	result chan string
	err    chan error
}

// AsyncFunction menjalankan tugas secara asinkron dan mengembalikan Future
func AsyncFunction() *Future {
	f := &Future{
		result: make(chan string, 1),
		err:    make(chan error, 1),
	}

	go func() {
		time.Sleep(2 * time.Second) // Simulasi delay

		if time.Now().Unix()%2 == 0 {
			f.result <- "Data sukses!"
		} else {
			f.err <- errors.New("Terjadi kesalahan dalam Future")
		}
	}()

	return f
}

// Get akan mengambil hasil dari Future (mirip dengan await)
func (f *Future) Get() (string, error) {
	select {
	case res := <-f.result:
		return res, nil
	case err := <-f.err:
		return "", err
	}
}

func main() {
	fmt.Println("Mulai tugas...")
	future := AsyncFunction()

	fmt.Println("Melakukan tugas lain...")

	// Ambil hasil dari Future (mirip await)
	result, err := future.Get()
	if err != nil {
		fmt.Println("Error Future:", err)
	} else {
		fmt.Println("Hasil Future:", result)
	}
}
```

✅ Mirip try...catch di Promise, bisa menangani error dengan baik.

## Kapan Menggunakan Future/Promise di Go?

🚀 Future sangat berguna untuk:

- Memanggil API secara asinkron → Misalnya fetching data dari third-party API tanpa memblokir eksekusi lainnya.
- Mengurangi Blocking dalam Goroutine → Memungkinkan eksekusi tetap berjalan tanpa menunggu satu tugas selesai duluan.
- Meningkatkan Performa → Membantu menangani pekerjaan berat seperti query database atau proses perhitungan besar tanpa menghentikan alur program.

## Kesimpulan

- Future/Promise di Go bisa dibuat menggunakan goroutine + channel untuk menjalankan operasi asinkron.
- Mirip dengan JavaScript async/await, tetapi tidak built-in, harus dibuat manual.
- Future bisa menangani error dengan select { case result <- chan, case err <- chan }.
- Sangat bermanfaat untuk pemanggilan API, query database, dan tugas berat lainnya tanpa memblokir eksekusi.

🚀 Jika terbiasa dengan async/await di JavaScript, Future di Go adalah cara terbaik untuk menulis kode asinkron yang lebih bersih dan efisien!