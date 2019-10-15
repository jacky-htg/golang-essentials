# Basic Golang
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
## Package
- Setiap program GO terdiri paket-paket. 
- Program muali berjalan dari paket utama (main package). 
- Dalam satu project hanya boleh ada satu package main
- Selain paket main, nama paket harus sama dengan nama folder.

## Type
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

## Constanta
```
const pi float64 = 22/7
const (
  alamat string = "Pondok Indah, Jakarta Selatan"
  email string = "emailku@rebelworks.co"
)
```

## Variable
```
package repositories
// contoh variabel yang siklus hidupnya ada dalam satu paket. Seluruh kode dalam paket repositories, biarpun berbeda file bisa mengakses variabel ini
var err error

func Satu() {
  // NOTE : siklus hidup variabel-variable ini hanya berlaku dalam fungsi Satu

  // variabel harus dideklarasikan terlebih dahulu
  var a int

  // variable yang telah dideklarasikan bisa diisi dengan nilai yang sesuai
  a = 1

  // variabel bisa dideklarasikan secara implisit dan sekaligus langsung diberi nilai. tipe akan disematkan secara implisit pada variabel ini
  b := 2 

  // siklus hidup variabel bisa hanya dalam blok yang membatasi. blok bisa berupa blok if, for, fungsi atau bahkan hanya notasi blok saja. 
  {
    var c string
    c = "variabel di dalam blok"
    println(c)
  }
}
```

## Function
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
