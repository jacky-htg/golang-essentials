# Pseudo OOP
Pada bab sebelumnya, kita sudah membahas [dasar-dasar pemrograman golang](https://github.com/jacky-htg/golang-essentials/blob/master/basic.md). Sebelum masuk ke pembahasan Pseudo OOP, ada baiknya kita melakukan [review dasar-dasar pemrograman golang](https://github.com/jacky-htg/golang-essentials/blob/master/review_basic.md) terlebih dahulu. 

Golang bukan merupakan bahasa pemrograman yang berorientasi objek. Tapi golang memiliki fitur seperti type, struct, method, reference dan interface yang memungkinkan untuk melakukan pemrograman yang mirip dengan OOP.

## Method
- Kita bisa mendefiniskan suatu method apada sebuah type.
- Method adalah fungsi yang mempunyai argumen khusus receiver berupa type.
```
package main

type MyStr string

func (m MyStr) Salam() {
    m = "Selamat Pagi"
    println(m)
}

func main() {
    var str MyStr
    str.Salam()
}
```
- Type yang bisa dibuatkan method adalah type local, yaitu type yang ada dalam paket yang sama dengan method yang dibuat. 
```
package main

// ini error karena string bukan type local dalam paket main
func (m string) Salam() {
    m = "Selamat Pagi"
    println(m)
}

func main() {
    var str string
    str.Salam()
}
```
- Receiver bisa berupa pointer
```
package main

type myStr string

func (m *myStr) Change() {
	*m = myStr("Selamat Sore")
}

func (m *myStr) Print() {
	println(*m)
}

func main() {
	str := myStr("Selamat Pagi")
	str.Print()
	str.Change()
	str.Print()
}
```

## Interface
- Interface berisi kumpulan yang berisi method yang abstract
```
type i interface{
    method()
}
```

- Type lain akan mengimplementasikan method dalam interface
- Tidak ada perintah implement, suatu interface akan dipenuhi secara emplisit begitu ada yang mengimplementasikannya 
```
package main

type i interface {
	method()
}

type myStr string

func (m *myStr) method() {
	println(*m)
}

func main() {
	var i i
	str := myStr("Hello")
	i = &str
	i.method()
}
```
- Jika suatu inetrface diinisiasi tapi tidak ada yang mengimplementasikannya akan terjadi error nil pointer dereference
```
package main

type i interface {
    method()
}

func main() {
    var i i
    i.method()
}
```
- Isi interface dapat dibayangkan sebagai sebuah pasangan nilai dan sebuah tipe: `(nilai, type)`
```
package main

type i interface{
    method()
}

type myStr string

func (m *myStr) method() {
	println(*m)
}

func main() {
	var i i
	str := myStr("Hello")
	i = &str
	i.method()
    describe(i)
}

func describe(i I) {
	fmt.Printf("(%v, %T)\n", i, i)
}
```

## Interface Kosong
- Interface kosong merupakan interface yang tidak memiliki method
- Untuk mengklaim nilai interface harus dilakukan type asserting
```
var a interface{}
a = "string"
println(a.(string))

a = false
println(a.(bool))
if value, ok := a.(bool); ok {
    println(value)
}

myMap := map[string]interface{}{"Satu": true, "Dua": "string", "Tiga": uint(3)}
println(myMap["Satu"].(bool))
println(myMap["Dua"].(string))
println(myMap["Tiga"].(uint)) 
```
- Penggunaan switch type dalam melakukan asserting
```
package main

type myStr string

func main() {
    var a interface{}
    a = myStr("Jacky")
    
    switch t := a.(type) {
        case string :
            println("type string", t)
        case bool :
            println("type bool", t)
        case myStr :
            println("type myStr", t)
        default :
            println("type lainnya", t)
    }
}
```

## Pseudo Object
- tidak ada class dalam go, tapi kita bisa menggunakan type
- variable class diganti dengan type struct
- method class diganti dengan method dengan pointer reference
- gunakan kata kunci new() untuk membuat object
```
package main

import (
	"fmt"
)

type becak struct {
	roda  int
	warna string
}

func (o *becak) caraJalan() string {
	return "dikayuh"
}

func main() {
	becak1 := becak{roda: 3, warna: "biru"}
	fmt.Printf("%v, %T\n", becak1, becak1)
	println("cara jalan:", becak1.caraJalan())

	becak2 := &becak1
	fmt.Printf("%v, %T\n", becak2, becak2)
	println("cara jalan:", becak2.caraJalan())

	becak3 := new(becak)
	becak3.roda = 3
	becak3.warna = "merah"
	fmt.Printf("%v, %T\n", becak3, becak3)
	println("cara jalan:", becak3.caraJalan())
}
```

### Method Overloading
- Method overloading dimungkinkan dengan reference yang berbeda
```
package main

import (
	"fmt"
)

type becak struct {
	roda  int
	warna string
}

type gerobak struct {
    roda int
    warna string
} 

func (o *becak) caraJalan() string {
	return "dikayuh"
}

func (o *gerobak) caraJalan() string {
	return "didorong"
}

func main() {
	becak := new(becak)
	println("becak", "cara jalan:", becak.caraJalan())

    gerobak := new(gerobak)
    println("gerobak", "cara jalan:", gerobak.caraJalan())
}
``` 

### Encapsulation
- Encapsulasi terjadi di level paket.
- Kita bisa memilih informasi (type, variabel, fungsi dll) yang hendak diexport ke luar paket dan mana yang hanya bisa diakses dalam paket yang sama.
- Penamaan informasi yang bersifat publik diawali dengan huruf besar.
- Penamaan informasi yang bersifat privat diawali dengan huruf kecil.
```
// file APP/latihan/kendaraan.go
package latihan

// Kendaraan interface
type Kendaraan interface {
	CaraJalan() string
	SetWarna(string)
	GetWarna() string
	GetRoda() int
}

type becak struct {
	roda  int
	warna string
}

func (o *becak) SetWarna(s string) {
	o.warna = s
}

func (o *becak) GetWarna() string {
	return o.warna
}

func (o *becak) GetRoda() int {
	return 3
}

func (o *becak) CaraJalan() string {
	return "dikayuh"
}

// NewBecak function untuk membuat objek becak
func NewBecak() Kendaraan {
	return &becak{}
}
```

```
package main

import (
	"golang-essentials/latihan"
)

func main() {
	becak := latihan.NewBecak()
	becak.SetWarna("Biru")
	println(becak.CaraJalan())
	println("jumlah roda:", becak.GetRoda())
	println("warna:", becak.GetWarna())
}

```

### Inheritance
- Go memungkinkan inheritance melalui embedded berupa field anonim
```
package main

import (
    "fmt"
)

type User struct {
    Name string
    Gender string
    Address
}

type Address struct {
    Street string
    Number string
    City string
    Zipcode string
}

func main () {
    user := new(User)
    user.Name = "Wiro"
    user.Gender = "Male"
    user.Street = "Marlioboro"
    user.Number = "212"
    user.City = "Jogja"
    
    fmt.Printf("%v", user)
}
```
- Tapi banyak programmer golang yang tidak menyarankan untuk melakukan inheritance. Melainkan melakukan pendekatan object composition.

### Object Composition
- Daripada melakukan pseudo inheritance melalui embedded, disarankan untuk melakukan object composition
```
package main

import (
    "fmt"
)

type User struct {
    Name string
    Gender string
    Address Address
}

type Address struct {
    Street string
    Number string
    City string
    Zipcode string
}

func main () {
    user := new(User)
    user.Name = "Wiro"
    user.Gender = "Male"
    user.Address.Street = "Marlioboro"
    user.Address.Number = "212"
    user.Address.City = "Jogja"
    
    fmt.Printf("%v", user)
}
```

### Polymorphism
```
package main

import "fmt"

type Hewan struct {
	Nama  string
	Nyata bool
}

func (c *Hewan) Cetak() {
	fmt.Printf("Nama: '%s', Nyata: %t\n", c.Nama, c.Nyata)
}

type HewanTerbang struct {
	Hewan
	PanjangSayap int
}

func (c HewanTerbang) Cetak() {
	fmt.Printf("Nama: '%s', Nyata: %t, PanjangSayap: %d\n", c.Nama, c.Nyata, c.PanjangSayap)
}

type Unicorn struct {
	Hewan
}

type Naga struct {
	HewanTerbang
}

type Pterodactilus struct {
	HewanTerbang
}

func NewPterodactyl(panjangSayap int) *Pterodactilus {
	p := new(Pterodactilus)
	p.Nama = "Pterodactilus"
	p.Nyata = true
	p.PanjangSayap = panjangSayap

	return p
}

func main() {
	hewan := new(Hewan)
	hewan.Nama = "Sembarang hewan"
	hewan.Nyata = false

	uni := new(Unicorn)
	uni.Nama = "Unicorn"
	uni.Nyata = false

	p1 := new(Pterodactilus)
	p1.Nama = "Pterodactilus"
	p1.Nyata = true
	p1.PanjangSayap = 5

	p2 := NewPterodactyl(8)

	hewan.Cetak()
	uni.Cetak()
	p1.Cetak()
	p2.Cetak()

	animals := []*Hewan{
		hewan,
		&uni.Hewan,
		&p1.Hewan,
		&p2.Hewan,
	}
	fmt.Println("Cetak() melalui  embedded type Hewan")
	for _, c := range animals {
		c.Cetak()
	}
}

```