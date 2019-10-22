# Review Basic Golang
Di materi sebelumnya kita sudah membahas [dasar-dasar pemrograman golang](https://github.com/jacky-htg/golang-essentials/blob/master/basic.md). Namun ada beberapa poin penting yang terlewat dari pembahasan. Karena itu, ditulislah bab ini untuk mereview sekaligus membahas hal-hal yang telah terlewatkan.  

## Membuat Project
- go mod init golang-essentials

## Cara Deklarasi dan Inisiasi Variabel
```
package main

// MyStr is custome type of string
type MyStr string

func main() {
	str := "Halo"
	println(str)

	var str1 string
	str1 = "Apa Kabar"
	println(str1)

	var str2 string = "Selamat Pagi"
	println(str2)

	var str3 MyStr
	str3 = "Selamat Siang"
	println(str3)

	var str4 MyStr = "Selamat Sore"
	println(str4)

	str5 := MyStr("Selamat Malam")
	println(str5)
}
```

## Package, Export dan Import
- Semua type, var, const, func dalam suatu paket yang sama bisa dipanggil di manapun (biar pun berbeda file)
- Untuk bisa diexport, penamaan var, type, const, fungsi dll harus dimulai dengan huruf besar
- Jika suatu paket ingin menggunakan kode dari paket lainnya, harus diimport terlebih dahulu

```
// file APP/latihan/satu.go
package latihan

// MyStr type bisa diexport
type MyStr string

// Salam bisa diexport
func Salam (m MyStr) {
    println(m)
    cetaknama()
}

// cetaknama tidak bisa diexport
func cetaknama () {
    println(nama)
}
```
```
// file APP/latihan/dua.go
package latihan

// nama var tidak bisa diexport, tapi bisa gunakan di seluruh program dalam paket latihan
var nama string = "Jacky"

// Nama bisa diexport
func Nama() {
    println(nama)
}
```
```
// file APP/main.go
package main

import "golang-essentials/latihan"

func main() {
    	str := latihan.MyStr("Selamat Pagi")
	println(str)

	latihan.Salam(latihan.MyStr("Selamat Sore"))

	//latihan.cetaknama()
	latihan.Nama()
}
```

## Type Casting
- lebih jauh tentang strconv bisa melihat langsung ke paket [strconv](https://golang.org/pkg/strconv)
```
package main

func main() {
	var myInt int
	myInt = 1

	var myUint uint
	myUint = uint(myInt)
	println(myUint)

	myUint32 := uint32(1)
	myUint64 := uint64(myUint32)
	println(myUint64)

	str := string("1")
	myInt, err := strconv.Atoi(str)
	if err != nil {
		panic(err)
	}

	println(myInt)

	str = strconv.Itoa(myInt)
	println(str)
}
```

## Pengoptimalan penggunaan memory dalam siklus hidup variabel 
- Jika ingin membuat variabel global dalam satu paket, sebaiknya pertimbangkan kembali, karena siklus hidupnya ada di seluruh kode dalam paket tersebut
- Untuk menghemat memori, deklarasikan variabel sesuai dengan kebutuhan siklus hidupnya
```
package main

func main() {
	// variabel i akan tetap hidup walaupun looping for sudah selesai
	i := 0
	for i < 10 {
		println(i)
		i++
	}

	// variabel i hanya hidup dalam blok for
	for i := 0; i < 10; i++ {
		println(i)
	}

	myMap := map[string]string{"Satu": "Ahad", "Dua": "Senin", "Tiga": "Selasa"}

	// variabel value dan ok tetap hidup walaupun blok if / if else sudah berakhir
	value, ok := myMap["Satu"]
	if ok {
		println(value)
	}

	// variabel value dan ok hanya hidup dalam blok if / if else
	if value, ok := myMap["Dua"]; ok {
		println(value)
	}

	myName := string("Jet Lee")
	{
		name := string("Jacky")
		println(name)
	}

	println(myName)
}
```
