# Single Flight Pattern

Single Flight adalah pola yang digunakan untuk mencegah redundant request dengan memastikan bahwa hanya satu goroutine yang menjalankan proses tertentu dalam satu waktu. Goroutine lain yang meminta hasil yang sama akan menunggu hasil dari goroutine pertama, bukan memproses ulang permintaan yang sama.

Pola ini sangat berguna untuk:

- Mengurangi load ke database atau API eksternal (misal: caching atau fetching data).
- Menghindari spam request ke third-party API, sehingga lebih efisien.
- Menghindari race condition saat banyak goroutine meminta data yang sama.
- Meningkatkan efisiensi dalam sistem dengan banyak request paralel.

## Kapan Teknik Ini Berguna?

- Ketika banyak request ke API yang sama dalam waktu bersamaan.
- Jika API third-party memiliki rate limit dan kita ingin menghindari throttling.
- Untuk mengurangi latensi dengan menghindari redundant request.
- Untuk menghemat biaya jika API third-party menggunakan sistem berbayar per request.

## Cara Menentukan Unique Key
Seperti yang kita lihat dari contoh kode implmentasi single flight patter, ada satu unique-key yang digunakan, sehingga request-request yang memiliki unique key yang sama, hanya akan diproses 1x. Unique-key bisa digenerate dengan berbagai logic, untuk kasus pemanggilan api third party, unique-key bisa menggunakan path url termasuk dengan parameter/query yang digunakan. Jika url, path dan parameter dirasa terlalu panjang, bisa menggunakan hashing agar lebih ringkas.

```go
key := fmt.Sprintf("%x", sha256.Sum256([]byte(urlWithParams)))
```

## Implementasi Single Flight Pattern 

```go
package main

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"net/http"
	"sync"
	"time"
)

// MySingleFlight adalah struktur untuk menangani request tunggal per key
type MySingleFlight struct {
	mu    sync.Mutex
	calls map[string]*call
}

// call menyimpan informasi tentang request yang sedang berlangsung
type call struct {
	wg  sync.WaitGroup
	res string
	err error
}

// NewMySingleFlight membuat instance MySingleFlight
func NewMySingleFlight() *MySingleFlight {
	return &MySingleFlight{
		calls: make(map[string]*call),
	}
}

// Do memastikan hanya satu request per key yang berjalan pada satu waktu
func (sf *MySingleFlight) Do(key string, fn func() (string, error)) (string, error) {
	sf.mu.Lock()
	if c, found := sf.calls[key]; found {
		sf.mu.Unlock()
		c.wg.Wait() // Tunggu hasil request yang sedang berjalan
		return c.res, c.err
	}

	// Jika belum ada request, buat yang baru
	c := &call{}
	c.wg.Add(1)
	sf.calls[key] = c
	sf.mu.Unlock()

	// Jalankan request
	c.res, c.err = fn()
	c.wg.Done()

	// Hapus dari map setelah selesai
	sf.mu.Lock()
	delete(sf.calls, key)
	sf.mu.Unlock()

	return c.res, c.err
}

// Hash URL dengan SHA-256 sebagai key
func hashURL(url string) string {
	hash := sha256.Sum256([]byte(url))
	return hex.EncodeToString(hash[:])
}

// Fetch API menggunakan MySingleFlight
func fetchAPI(sf *MySingleFlight, url string) (string, error) {
	key := hashURL(url)

	return sf.Do(key, func() (string, error) {
		fmt.Println("Fetching API:", url) // Indikasi request benar-benar terjadi
		resp, err := http.Get(url)
		if err != nil {
			return "", err
		}
		defer resp.Body.Close()

		body, err := io.ReadAll(resp.Body)
		if err != nil {
			return "", err
		}

		return string(body), nil
	})
}

func main() {
	url := "https://example.com/todos/1" // API contoh
	sf := NewMySingleFlight()

	var wg sync.WaitGroup
	numRequests := 3

	wg.Add(numRequests)
	for i := 0; i < numRequests; i++ {
		go func(id int) {
			defer wg.Done()
			data, err := fetchAPI(sf, url)
			if err != nil {
				fmt.Printf("Goroutine %d error: %v\n", id, err)
			} else {
				fmt.Printf("Goroutine %d result: %s\n", id, data)
			}
		}(i)
	}

	wg.Wait()
}
```

Penjelasan Kode :

- Membuat MySingleFlight

    - Menggunakan map[string]*call untuk menyimpan request yang sedang berjalan.
    - Menggunakan sync.Mutex agar hanya satu goroutine yang bisa memodifikasi map pada satu waktu.
    - Request kedua dan seterusnya akan menunggu hasil request pertama.

- Struktur call
    - wg sync.WaitGroup: Menunggu hasil request yang sedang berlangsung.
    - res string: Menyimpan hasil response.
    - err error: Menyimpan error jika terjadi.

- Mekanisme Do()
    - Jika request dengan key tertentu sudah berjalan, goroutine menunggu hasilnya (c.wg.Wait()).
    - belum ada request, membuat request baru dan menyimpannya di map.
    - Setelah request selesai, hapus entri dari map agar request baru bisa dilakukan.

- Memanggil API dengan Hashing
    - Menggunakan SHA-256 hash dari URL sebagai key untuk menghindari duplikasi request.

- Menjalankan fetchAPI() dengan Beberapa Goroutine
    - Tiga goroutine menjalankan request bersamaan.
    - Hanya satu request yang benar-benar dikirim, sisanya menunggu hasilnya.

## Output yang diharapkan

```
Fetching API: https://example.com/todos/1
Goroutine 0 result: {"userId":1,"id":1,"title":"lorem ipsum delectus aut autem","completed":false}
Goroutine 1 result: {"userId":1,"id":1,"title":"lorem ipsum delectus aut autem","completed":false}
Goroutine 2 result: {"userId":1,"id":1,"title":"lorem ipsum delectus aut autem","completed":false}
```

- Fetching API: hanya muncul sekali, menandakan hanya satu request yang benar-benar dikirim.
- Semua goroutine mendapatkan hasil yang sama tanpa harus request ulang.

## Implementasi Menggunakan Library "golang.org/x/sync/singleflight"

Saat ini, sudah ada library single-flight pattern yang cukup populer di golang. Pertimbangkan untuk menggunakan library ini agar kita tidak perlu membuatnya from scratch.

```go
package main

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"net/http"
	"sync"
	"time"

	"golang.org/x/sync/singleflight"
)

var sf singleflight.Group

// Hash URL dengan SHA-256 untuk digunakan sebagai key
func hashURL(url string) string {
	hash := sha256.Sum256([]byte(url))
	return hex.EncodeToString(hash[:])
}

// Fetch data dari API third-party menggunakan SingleFlight
func fetchAPI(url string) (string, error) {
	key := hashURL(url) // Gunakan hash URL sebagai key

	// Gunakan SingleFlight untuk mencegah duplikasi request
	result, err, _ := sf.Do(key, func() (interface{}, error) {
		fmt.Println("Fetching API:", url) // Indikasi request benar-benar terjadi
		resp, err := http.Get(url)
		if err != nil {
			return "", err
		}
		defer resp.Body.Close()

		body, err := io.ReadAll(resp.Body)
		if err != nil {
			return "", err
		}

		return string(body), nil
	})

	if err != nil {
		return "", err
	}
	return result.(string), nil
}

func main() {
	url := "https://example.com/todos/1" // API contoh

	var wg sync.WaitGroup
	numRequests := 3

	wg.Add(numRequests)
	for i := 0; i < numRequests; i++ {
		go func(id int) {
			defer wg.Done()
			data, err := fetchAPI(url)
			if err != nil {
				fmt.Printf("Goroutine %d error: %v\n", id, err)
			} else {
				fmt.Printf("Goroutine %d result: %s\n", id, data)
			}
		}(i)
	}

	wg.Wait()
}
```

## Kesimpulan

SingleFlight adalah solusi yang efisien dan sederhana untuk menghindari eksekusi berulang dari tugas yang sama dalam lingkungan konkuren. Jika ingin kontrol penuh, kita bisa membuat SingleFlight buatan sendiri. Jika ingin implementasi cepat dan stabil, cukup gunakan sync/singleflight.

🚀 Dengan menggunakan SingleFlight, kita bisa meningkatkan performa aplikasi secara signifikan dan menghindari pemborosan resource!