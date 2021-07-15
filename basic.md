# Basic Golang
Bab ini membahas dasar-dasar pemrograman golang
 
## Install
Anda dapat membaca [dokumentasi instalasi golang](https://golang.org/doc/install)

## Hello world
- Buat folder baru untuk memulai project
- Buat file main.go yang berisi :
```
package main

func main(){
  println("Hello World")
}
```  
## Fundamental
### Package
- Setiap program GO terdiri paket-paket. 
- Program mulai berjalan dari paket utama (main package). 
- Dalam satu project hanya boleh ada satu package main
- Selain paket main, nama paket harus sama dengan nama folder.

### Type
- Go is a statically typed programming language
- Setiap variabel, konstanta dan lain-lain memiliki type
- tipe dasar :
```
bool

string

int  int8  int16  int32  int64
uint uint8 uint16 uint32 uint64 uintptr

byte // alias untuk uint8

rune // alias untuk int32
     // merepresentasikan sebuah kode Unicode

float32 float64

complex64 complex128
``` 
- Ada tipe turunan seperti array, slice, map, interface, struct, dan function

### Constanta
```
const pi float64 = 22/7
const (
  alamat string = "Pondok Indah, Jakarta Selatan"
  email string = "emailku@rebelworks.co"
)
```

### Variable
```
package repositories
// contoh variabel yang siklus hidupnya ada dalam satu paket. 
// Seluruh kode dalam paket repositories, biarpun berbeda file bisa mengakses variabel ini
var err error

func Satu() {
  // NOTE : siklus hidup variabel-variable ini hanya berlaku dalam fungsi Satu

  // variabel harus dideklarasikan terlebih dahulu
  var a int

  // variable yang telah dideklarasikan bisa diisi dengan nilai yang sesuai
  a = 1

  // variabel bisa dideklarasikan secara implisit dan sekaligus langsung diberi nilai. 
  // tipe akan disematkan secara implisit pada variabel ini
  b := 2 

  // siklus hidup variabel bisa hanya dalam blok yang membatasi. 
  // blok bisa berupa blok if, for, fungsi atau bahkan hanya notasi blok saja. 
  {
    var c string
    c = "variabel di dalam blok"
    println(c)
  }
}
```

### Function
- fungsi juga merupakan sebuah tipe
```
type Handler func(http.ResponseWriter, *http.Request)
type CustomeHandler func(http.ResponseWriter, *http.Request) error 
```
- Format fungsi : func NAMA (argument type) type_return 
```
func Jumlah (a int, b int) int {
  return a+b
}
```
```
type operasi func(a int, b int) int
func main() {
  // Lambda
  println(func() string {
      return "lambda"
    }())

  // Closure
  var GetClosure = func() string {
    return "closure"
  }

  var closure string
  closure = GetClosure()
  println(closure)
```
```
  // Callback
  println(square(func(i int) int {
	return i * i
  }, 2))
	
  // Callback
  var Jumlah = func(a int, b int) int {
	return a + b
  }
  var Kurang = func(a int, b int) int {
	return a - b
  }

  println("Operasi Jumlah : ", Hitung(Jumlah, 5, 3))
  println("Operasi Kurang : ", Hitung(Kurang, 5, 3))
}

func square(f func(int) int, x int) int {
	return f(x * x)
}

func Hitung(o operasi, x int, y int) int {
	return o(x, y)
} 
```
## Flow Control
### if
```
if err != nil {
	return err
}

if err := run(); err != nil {
	return err
}

if b := 1; b < 10 {
	println("blok if", b)	
} else {
	println("blok else", b)
}
```

### switch
```
func main() {
	fmt.Print("Go berjalan pada ")
	switch os := runtime.GOOS; os {
	case "darwin":
		fmt.Println("OS X.")
	case "linux":
		fmt.Println("Linux.")
	default:
		// freebsd, openbsd,
		// plan9, windows...
		fmt.Printf("%s.\n", os)
	}

	t := time.Now()
	switch {
	case t.Hour() < 12:
		fmt.Println("Selamat pagi!")
	case t.Hour() < 17:
		fmt.Println("Selamat sore.")
	default:
		fmt.Println("Selamat malam.")
	}
}
```

### for 
```
func main() {
	// standard for
	for i:=0; i<=10; i++ {
		println(i)
	}

	// while
	i := 0
	for i<=10 {
		println(i)
		i++
	}

	// infinite loop
	i = 0
	for {
		println(i)
		if i == 10 {
			break
		}
		i++
	}

	// foreach
	array := []uint{0,1,2,3,4,5,6,7,8,9,10}
	for index, value := range array {
		println(index, value)
	}
}
```
### defer
- Perintah defer menunda eksekusi dari sebuah fungsi sampai fungsi yang melingkupinya selesai.
- Argumen untuk pemanggilan defer dievaluasi langsung, tapi pemanggilan fungsi tidak dieksekusi sampai fungsi yang melingkupinya selesai.
```
func main() {
	defer println("datang")
	println("selamat")
}
```
- Jika ada tumpukan perintah defer, maka akan dieksekusi secara LIFO (last In First Out)
```
func main() {
	defer println("pertama")
	for i:=0; i<= 10; i++ {
		defer println(i)
	}
	defer println("terakhir")
	println("normal")
}
```

## Array dan Struct
### Pointer
```
func main() {
	i := 10
	p := &i         // menunjuk ke i
	println(*p) 	// baca i lewat pointer
	*p = 20         // set i lewat pointer
	println(i)  	// lihat nilai terbaru dari i
}

```
### Array
```
var salam [2]string
salam[0] = "selamat"
salam[1] = "pagi"
fmt.Println(salam)

greeting := []string{"Good", "Morning"}
fmt.Println(greeting)
```
### Slice
- Merupakan potongan dari sebuah array
```
var musim [3]string
musim[0] = "panas"
musim[1] = "panas-sekali"
musim[2] = "super-duper-panas"
fmt.Println(musim)
slice := musim[1:2]
fmt.Println(slice)
slice = musim[:2]
fmt.Println(slice)
slice = musim[1:]
fmt.Println(slice)
```
### Map
- kalau di PHP ini seperti assosiatif array.
- index otomatis disort secara alpabet
```
hari := map[string]int{"Senin":1, "Selasa":2, "Rabu":3}
fmt.Println(hari)
``` 
### Common Operation
- array tidak dideklarasikan dengan kapasitasnya
- untuk menambahkan anggota dengan menggunakan fungsi append  
```
var salam []string
salam = append(salam, "selamat")
salam = append(salam, "pagi")
fmt.Println(salam)
```
```
func main() {
	buah := []string{"rambutan", "durian", "salak"}
	exist, index := InArray("duku", buah)
	println(exist, index)

	buah = Remove("durian", buah)
	fmt.Println(buah)
	buah = append(buah, "mangga")
	fmt.Println(buah)
	exist, index = InArray("salak", buah)
	if exist {
		buah = RemoveByIndex(index, buah)
	}
	fmt.Println(buah)	
}

func InArray(val string, array []string) (bool, int) {
	for i, s := range array {
		if s == val {
			return true, i	
		}
	}

	return false, -1
}

func Remove(val string, array []string) []string {
	isExist, index := InArray(val, array)
	if isExist {
		if index == 0 {
			array = array[1:]
		} else {
			array = append(array[:index], array[(index+1):]...)
		}
	}

	return array
}

func RemoveByIndex(index int, array []string) []string {
	if index == 0 {
		return array[1:]
	} else {
		return append(array[:index], array[(index+1):]...)
	}
}
```
### struct
- sebuah tipe data abstract
- berisi dari kumpulan dari berbagai type
```
type User struct {
	ID uint64
	Name string	
}

func main() {
	var user User
	user.ID = 1
	user.Name = "Jacky"
	fmt.Printf("%v\n", user)
	println(user.Name)

	user2 := User{ID: 2, Name: "JetLee"}
	fmt.Printf("%v\n", user2)
	println(user2.Name)
}
```

## [Review](https://github.com/jacky-htg/golang-essentials/blob/master/review_basic.md)
Untuk mempertajam materi, dan membahas hal-hal penting yang terlewat, telah dibuat materi tambahan untuk [mereview dasar-dasar pemrograman golang](https://github.com/jacky-htg/golang-essentials/blob/master/review_basic.md).