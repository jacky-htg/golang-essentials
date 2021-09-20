# Konkurensi
Konkurensi adalah komposisi / struktur dari berbagai proses yang berjalan secara bersamaan. Fitur untuk melakukan konkurensi dalam golang adalah Go Routine.

## Go Routine
- Sebuah thread yang ringan, hanya dibutuhkan 2kB memori untuk menjalankan sebuah go routine
- Aksi go routine bersifat asynchronous, jadi tidak saling menunggu dengan go routine yang lain.
- Proses yang hendak dieksekusi sebagai go routine harus berupa fungsi tanpa return yang dipanggil dengan kata kunci go


```
package main

import "time"

func Salam(s string) {
    for i := 0; i <= 10; i++ {
	println(s)
	time.Sleep(1000 * time.Millisecond)
    }
}

func main() {
    // salam tidak pernah tercetak karena dijalankan secara konkuren. 
    // Sehingga tidak akan ditunggu oleh func main, dan langsung exit.  
    go Salam("Selamat Pagi")
}
```

```
package main

import "time"

func Salam(s string) {
    for i := 0; i <= 10; i++ {
	println(s)
	time.Sleep(1000 * time.Millisecond)
    }
}

func main() {
    // salam tidak pernah tercetak karena dijalankan secara konkuren. 
    // Sehingga tidak akan ditunggu oleh func main, dan langsung exit.
    go Salam("Selamat Pagi")
    println("Halo")
}

```

```
package main

import "time"

func Salam(s string) {
    for i := 0; i <= 10; i++ {
	println(s)
	time.Sleep(1000 * time.Millisecond)
    }
}

func main() {
    go Salam("Selamat Pagi")
    Salam("Selamat Malam")
}
```

- Go routine jalan di multi core processor, dan bisa diset mau jalan di berapa core.

```
package main

import "time"

func Salam(s string) {
    for i := 0; i <= 10; i++ {
	println(s)
	time.Sleep(1000 * time.Millisecond)
    }
}

func main() {
    runtime.GOMAXPROCS(1)

    go Salam("Selamat Pagi")
    Salam("Selamat Malam")
}
```

## Channel
- Untuk mengsinkronkan satu go routine dengan go routine lainnya, diperlukan channel
- Channel digunakan untuk menerima dan mengirim data antar go routine.
- Channel bersifat blocking / synchronous. Pengiriman dan penerimaan ditahan sampai sisi yang lain siap.
- Channel harus dibuat sebelum digunakan, dengan kombinasi kata kunci make dan chan
- Aliran untuk menerima / mengirim data ditunjukkan dengan arah panah

```
package main

import "runtime"

func main() {
    var pesan = make(chan string)
    println("kirim data", "Jacky")
    pesan <- "Jacky"

    // akan error karena tidak ada go routine lain yang menangkap channel
    println("terima data", <-pesan)	
}
```

```
package main

func main() {
    var pesan = make(chan string)

    println("kirim data", "Jacky")
    pesan <- "Jacky"

    // error karena go routine yang menangkap channel belum dieksekusi ketika exit program 
    go func() {
	println("terima data", <-pesan)
    }()
	
}
```

```
package main

func main() {
    var pesan = make(chan string)

    go func() {
	println("terima data", <-pesan)
    }()

    println("kirim data", "Jacky")
    pesan <- "Jacky"

}
```

```
package main

func main() {
    a := []string{"Jacky", "Jet Lee", "Bruce Lee", "Samo Hung"}
    
    var pesan = make(chan string)
	
    // error karena go routine yang melakukan penerimaan data hanya sekali, sementara pengiriman dilakukan 4 kali 
    go func() {
	println("terima data", <-pesan)
    }()

    for _, s := range a {
	println("kirim data", s)
	pesan <- s
    }
}

```

```
package main

func main() {
    a := []string{"Jacky", "Jet Lee", "Bruce Lee", "Samo Hung"}

    var pesan = make(chan string)
	
    go func() {
	// catatan: looping tanpa henti termasuk boros cpu, 
	// di materi selanjutnya ada cara tanpa menggunakan for{}
	// baik melalui for range maupun for break 
        for {
	    println("terima data", <-pesan)
        }
    }()

    for _, s := range a {
	println("kirim data", s)
	pesan <- s
    }
}
```

## Channel dengan buffer
- Panjang buffer ditambahkan pada fungsi make sebagai argumen kedua
- Buffering menyebabkan pengiriman dan penerimaan data berlangsung secara asynchronous
- Pengiriman ke kanal buffer akan ditahan bila buffer telah penuh. Penerimaan akan ditahan saat buffer kosong.
- Jika pengiriman data melebihi panjang buffer, maka akan diperlakukan secara synchronous.

```
package main

func main() {
    a := []string{"Jacky", "Jet Lee", "Bruce Lee", "Samo Hung"}

    var pesan = make(chan string, 3)
	
    go func() {
	for {
	    println("terima data", <-pesan)
	}
    }()

    for _, s := range a {
	println("kirim data", s)
	pesan <- s
    }
}
```  

```
package main

func main() {
    a := []string{"Jacky", "Jet Lee", "Bruce Lee", "Samo Hung"}

    var pesan = make(chan string, len(a))

    go func() {
	println("terima data", <-pesan)
    }()

    for _, s := range a {
	println("kirim data", s)
	pesan <- s
    }
}

```

```
package main

func main() {
    a := []string{"Jacky", "Jet Lee", "Bruce Lee", "Samo Hung"}
    var pesan = make(chan string, len(a))
    for _, s := range a {
	println("kirim data", s)
	pesan <- s
    }
}
```

## Range dan Close
- Range merupakan perulangan dari sebuah channel

```
package main

func main() {
    a := []string{"Jacky", "Jet Lee", "Bruce Lee", "Samo Hung"}

    var pesan = make(chan string, len(a)-1)

    go func() {
	for i := range pesan {
	    println("terima data", i)
	}
    }()

    for _, s := range a {
	println("kirim data", s)
	pesan <- s
    }

}
```

- Pengirim bisa menutup sebuah channel untuk menandai sudah tidak ada data yang dikirim lagi.
- Penutupan ini hanya optional. Artinya pengirim boleh melakukan close maupun tidak.
- Yang melakukan close hanya pengirim. Karena jika yang melakukan close adalah penerima, dan ada routine yang melakukan pengrimana akan menyebabkan panic.
- Penerima bisa menambahkan pengecekan, jika masih ada data yang dikirim maka akan diterima. 

```
package main

func main() {
    a := []string{"Jacky", "Jet Lee", "Bruce Lee", "Samo Hung"}

    var pesan = make(chan string, len(a)-1)

    go func() {
	for {
	    println("terima data", <-pesan)
	    close(pesan)
	}
    }()

    for _, s := range a {
	println("kirim data", s)
	pesan <- s
    }
}

```

```
package main

func main() {
    a := []string{"Jacky", "Jet Lee", "Bruce Lee", "Samo Hung"}

    var pesan = make(chan string, len(a)-1)

    go func() {
	for {
	    println("terima data", <-pesan)
	}
    }()

    for _, s := range a {
	println("kirim data", s)
	pesan <- s
    }
    close(pesan)
}
```

```
package main

func main() {
    a := []string{"Jacky", "Jet Lee", "Bruce Lee", "Samo Hung"}

    var pesan = make(chan string, len(a))

    for _, s := range a {
	println("kirim data", s)
	pesan <- s
    }
    close(pesan)
}

```

```
package main

func main() {
    a := []string{"Jacky", "Jet Lee", "Bruce Lee", "Samo Hung"}

    var pesan = make(chan string, len(a))

    for _, s := range a {
	println("kirim data", s)
	pesan <- s
    }
    close(pesan)

    for {
	println("terima data", <-pesan)
    }
}
```

```
package main

func main() {
    a := []string{"Jacky", "Jet Lee", "Bruce Lee", "Samo Hung"}

    var pesan = make(chan string, len(a))

    for _, s := range a {
	println("kirim data", s)
	pesan <- s
    }
    close(pesan)

    for {
        // dilakukan pengecekan agar tidak looping forefer
	if v, ok := <-pesan; ok {
	    println("terima data", v)
	} else {
	    break
	}
    }
}

```

```
package main

func main() {
    a := []string{"Jacky", "Jet Lee", "Bruce Lee", "Samo Hung"}

    var pesan = make(chan string, len(a))

    // menggunakan range jauh lebih simple
    for _, s := range a {
	println("kirim data", s)
	pesan <- s
    }
    close(pesan)

    for i := range pesan {
	println("terima data", i)
    }
}
```

## Select
- Channel diperlukan untuk pertukaran data antar go routine
- Jika melibatkan lebih dari satu go routine, diperlukan fungsi kontrol melalui select
- Select akan menerima secara acak mana data yang terlebih dahulu tersedia

```
package main

func main() {
    var pesan = make(chan string)
    var c = make(chan int)

    go func() {
	for _, s := range []string{"Jacky", "Jet Lee", "Bruce Lee", "Samo Hung"} {
	    pesan <- s
	}
    }()

    go func() {
	c <- 5
    }()

    select {
    case i := <-c:
	println("terima data", i)
    case s := <-pesan:
	println("terima data", s)
    }
}
```

```
package main

func main() {
    var pesan = make(chan string)
    var c = make(chan int)

    go func() {
	for _, s := range []string{"Jacky", "Jet Lee", "Bruce Lee", "Samo Hung"} {
	    pesan <- s
	}
    }()

    go func() {
	c <- 5
    }()

    for a := 0; a <= 4; a++ {
	select {
	case i := <-c:
	    println("terima data", i)
	case s := <-pesan:
	    println("terima data", s)
	}
    }
}
```

```
package main

func main() {
    var pesan = make(chan string)
    var c = make(chan int)

    go func() {
	for _, s := range []string{"Jacky", "Jet Lee", "Bruce Lee", "Samo Hung"} {
	    pesan <- s
	}
    }()

    go func() {
	c <- 5
    }()

    for a := 0; a <= 5; a++ {
	select {
	case i := <-c:
	    println("terima data", i)
	case s := <-pesan:
	    println("terima data", s)
	}
    }
}

```
## Select Default
- Jika saat select tidak ada channel yang siap diterima maka akan dijalankan baris kode default

```
package main

func main() {
    var pesan = make(chan string)
    var c = make(chan int)

    go func() {
	for _, s := range []string{"Jacky", "Jet Lee", "Bruce Lee", "Samo Hung"} {
	    pesan <- s
	}
    }()

    go func() {
	c <- 5
    }()

    for a := 0; a <= 5; a++ {
	select {
	case i := <-c:
	    println("terima data", i)
	case s := <-pesan:
	    println("terima data", s)
        default :
            println("tidak ada penerimaan data")
	}
    }
}

``` 

## Select Timeout
- Teknik tambahan untuk mengakhiri select jika tidak ada penerimaan data

```
package main

import (
    "fmt"
    "time"
)

func main() {
    var pesan = make(chan string)
    var c = make(chan int)

    go func() {
	for _, s := range []string{"Jacky", "Jet Lee", "Bruce Lee", "Samo Hung"} {
	    pesan <- s
	}
    }()

    go func() {
	c <- 5
    }()

loop:
    for {
	select {
	case i := <-c:
	    println("terima data", i)
	case s := <-pesan:
	    println("terima data", s)
	case <-time.After(time.Second * 5):
	    fmt.Println("timeout. tidak ada aktivitas selama 5 detik")
	    break loop
	}
    }
}

```

Hati-hati jika ingin menggabungkan antara select timeout dengan default, karena bisa terjadi looping forever.

```
package main

import (
    "fmt"
    "time"
)

func main() {
    var pesan = make(chan string)
    var c = make(chan int)

    go func() {
	for _, s := range []string{"Jacky", "Jet Lee", "Bruce Lee", "Samo Hung"} {
	    pesan <- s
	}
    }()

    go func() {
	c <- 5
    }()

loop:
    for {
	select {
	case i := <-c:
	    println("terima data", i)
	case s := <-pesan:
	    println("terima data", s)
	case <-time.After(time.Second * 5):
	    fmt.Println("timeout. tidak ada aktivitas selama 5 detik")
	    break loop
	default:
	    println("tidak ada data diterima")
	}
    }
}
```

Ini disebabkan time.After(time.Second * 5) selalu dibuat setiap looping seleksi, untuk mengatasinya, buat variable untuk menampung timeout agar timeout dikenali disetiap looping.

```
package main

import (
    "fmt"
    "time"
)

func main() {
    var pesan = make(chan string)
    var c = make(chan int)

    go func() {
	for _, s := range []string{"Jacky", "Jet Lee", "Bruce Lee", "Samo Hung"} {
	    pesan <- s
	}
    }()

    go func() {
	c <- 5
    }()
    
    timeout := time.After(time.Second * 5)

loop:
    for {
	select {
	case i := <-c:
	    println("terima data", i)
	case s := <-pesan:
	    println("terima data", s)
	case <-timeout:
	    fmt.Println("timeout. tidak ada aktivitas selama 5 detik")
	    break loop
	default:
	    println("tidak ada data diterima")
	}
    }
}
```

## Sync Mutex
- Channel dipakai untuk komunikasi antar go routine
- Jika tidak ingin berkomunikasi karena ngin memastikan hanya satu goroutine yang dapat mengakses suatu variabel pada satu waktu untuk menghindari konflik, digunakan sync mutex
- mutex adalah mutual exclusion dengan fungsi `Lock` dan `Unlock`

```
package main

import (
    "fmt"
    "sync"
    "time"
)

// SafeCounter aman digunakan secara konkuren.
type SafeCounter struct {
    v   map[string]int
    mux sync.Mutex
}

// Inc meningkatkan nilai dari key.
func (c *SafeCounter) Inc(key string) {
    c.mux.Lock()
    // Lock sehingga hanya satu goroutine pada satu waktu yang dapat
    // mengakses map c.v.
    c.v[key]++
    c.mux.Unlock()
}

// Value mengembalikan nilai dari key.
func (c *SafeCounter) Value(key string) int {
    c.mux.Lock()
    // Lock sehingga hanya satu gorouting pada satu waktu yang dapat
    // mengakses map c.v.
    defer c.mux.Unlock()
    return c.v[key]
}

func main() {
    c := SafeCounter{v: make(map[string]int)}
    for i := 0; i < 1000; i++ {
	go c.Inc("key")
    }

    time.Sleep(time.Second)
    fmt.Println(c.Value("key"))
}

``` 

## Sync.WaitGroup
- Kadang kita perlu menjalankan satu group routine yang terdiri dari beberapa go routine.
- Kita ingin mengontrol group rutin tersebut dengan melakukan sinkronisasi (synchronous).
- Fitur sync.WaitGroup memungkinkan kita untuk menunggu semua group routine selesai.
- Untuk mencegah suatu routine berlangsung lama dibanding routine lainnya, dipasang context dengan deadline.
- Jika ada satu error di salah satu routine, maka seluruh routine yang sedang jalan akan dicancel.

### Group Routine

```
package main

import "fmt"

func main() {
	for i := 0; i < 10; i++ {
		go fmt.Printf("Routine ke: %d\n", i)
	}
}
```

- Jika dijalankan kemungkinan tidak ada hasil yang diprint, atau mungkin cuma ada 1x print.
- Tidak ada garansi apakah suatu routine bisa selesai dieksekusi.
- Go menjalankan fungsi main, dan ketika fungsi main berakhir, maka berakhir juga seluruh program.
- Kode di atas menjalankan sekelompok goroutine dan kemudian keluar sebelum mereka punya waktu untuk eksekusi.

### Wait Group
- Solusi untuk kasus di atas adalah dengan menggunakan standar library sync.WaitGroup

```
package main

import (
    "fmt"
    "sync"
)

func main() {
    var wg sync.WaitGroup
	for i := 0; i < 10; i++ {
        wg.Add(1)
		go func (id int) {
            defer wg.Done()
            fmt.Printf("Routine dengan id: %d\n", id)
        }(i)
	}
    wg.Wait()
}
```

- wg.Add() untuk counter berapa goroutine yang sudah ditambahkan. Setiap kali hendak menjalankan gouroutine, tambahkan counter dengan perintah wg,Add(1). 
- wg.Done() untuk menandai suatu routine sudah selesai
- wg.Wait() untuk menunggu seluruh counter routine sudah nol (semua goroutine telah selesai).
- Perhatikan saya mengenalkan variabel local id sebagai id sebuah goroutine. Ini adalah mekanisme aman menggunakan variabel local. Karena jika menggunakan varibel luar i, akan terjadi konflik karena menjalankan potensi race condition.
- Di bawah ini adalah contoh kode yang salah karena tidak menggunakan variabel local. 

```
package main

import (
	"fmt"
	"sync"
)

func main() {
	var wg sync.WaitGroup
	for i := 0; i < 10; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			fmt.Printf("Routine dengan id: %d\n", i)
		}()
	}
	wg.Wait()
}
```

### Handling Error
- Kode di atas sederhana dan optimis tidak terjadi error, padahal aplikasi riil pasti ada penanganan error.
- Library golang.org/x/sync/errgroup digunakan untuk handling error.
- Ingat untuk menggunakan variabel local sebagai id
- Error yang ditangkap adalah error pertama yang dihasilkan oleh routine. 

```
package main

import (
	"fmt"

	"golang.org/x/sync/errgroup"
)

func main() {
	var eg errgroup.Group
	for i := 0; i < 10; i++ {
		id := i
		eg.Go(func() error {
			return routine(id)
		})
	}

	if err := eg.Wait(); err != nil {
		fmt.Println("terjadi error: ", err)
		return
	}

	fmt.Println("sukses")
}

func routine(id int) error {
	fmt.Printf("Routine dengan id: %d\n", id)

	if id == 9 || id == 6 {
		return fmt.Errorf("simulasi error %d", id)
	}

	return nil
}

```

### Context
- context berguna untuk menjaga agar context dari client bisa diiikuti.
- context bisa digunakan untuk menyimpan variabel yang siklus hiduonya sesuai context.
- context bisa digunakan untuk melakukan deadline maupun cancell ation suatu fungsi.
- context bisa digunakan untuk melakukan cancellation kode saat context sdh berakhir.

```
package main

import (
	"context"
	"fmt"

	"golang.org/x/sync/errgroup"
)

func main() {
	eg, ctx := errgroup.WithContext(context.Background())
	for i := 0; i < 10; i++ {
		id := i
		eg.Go(func() error {
			return routineContext(ctx, id)
		})
	}

	if err := eg.Wait(); err != nil {
		fmt.Println("terjadi error: ", err)
		return
	}

	fmt.Println("sukses")
}

func routineContext(ctx context.Context, id int) error {
	select {
	case <-ctx.Done():
		fmt.Printf("context cancelled job %v terminting\n", id)
		return ctx.Err()
	default:
	}

	fmt.Printf("Routine dengan id: %d\n", id)

	if id == 9 || id == 6 {
		return fmt.Errorf("simulasi error %d", id)
	}

	return nil
}
```

Kita bisa menambahkan deadline suatu context

```
package main

import (
	"context"
	"fmt"
	"time"

	"golang.org/x/sync/errgroup"
)

func main() {
	ctx, cancel := context.WithTimeout(context.Background(), time.Microsecond)
	defer cancel()

	eg, ctx := errgroup.WithContext(ctx)
	for i := 0; i < 10; i++ {
		id := i
		eg.Go(func() error {
			return routineContext(ctx, id)
		})
	}

	if err := eg.Wait(); err != nil {
		fmt.Println("terjadi error: ", err)
		return
	}

	fmt.Println("sukses")
}

func routineContext(ctx context.Context, id int) error {
	select {
	case <-ctx.Done():
		fmt.Printf("context cancelled job %v terminting\n", id)
		return ctx.Err()
	default:
	}

	fmt.Printf("Routine dengan id: %d\n", id)

	if id == 9 || id == 6 {
		return fmt.Errorf("simulasi error %d", id)
	}

	return nil
}

```

Bandingkan jika kita tidak handle context, maka cancellation jadi tidak berfungsi.

```
package main

import (
	"context"
	"fmt"
	"time"

	"golang.org/x/sync/errgroup"
)

func main() {
	ctx, cancel := context.WithTimeout(context.Background(), time.Microsecond)
	defer cancel()

	eg, ctx := errgroup.WithContext(ctx)
	for i := 0; i < 10; i++ {
		id := i
		eg.Go(func() error {
			return routineContext(ctx, id)
		})
	}

	if err := eg.Wait(); err != nil {
		fmt.Println("terjadi error: ", err)
		return
	}

	fmt.Println("sukses")
}

func routineContext(ctx context.Context, id int) error {
	/*select {
	case <-ctx.Done():
		fmt.Printf("context cancelled job %v terminting\n", id)
		return ctx.Err()
	default:
	} */

	fmt.Printf("Routine dengan id: %d\n", id)

	if id == 9 || id == 6 {
		return fmt.Errorf("simulasi error %d", id)
	}

	return nil
}
```



