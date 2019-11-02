# Start up
- Start up me-listen semua domain, port 9000 dan menghandle-nya dengan fungsi helloworld 
- Untuk start up, kita menggunakan fungsi-fungsi pada [paket net/http](https://golang.org/pkg/net/http), yaitu http.HandlerFunc() dan http.ListenAndServe()

```
package main

import (
	"fmt"
	"log"
	"net/http"
)

func main() {

	// handler
	handler := http.HandlerFunc(helloworld)

	// start server listening
	if err := http.ListenAndServe("0.0.0.0:9000", handler); err != nil {
		log.Fatalf("error: listening and serving: %s", err)
	}
}

// helloworld: basic http handler with response hello world string
func helloworld(w http.ResponseWriter, r *http.Request) {
	fmt.Fprint(w, "Hello World!")
}
```
- Kita juga bisa mendefinisikan parameter parameter untuk menjalankan server http melalui struct [http.Server](https://golang.org/pkg/net/http/#Server)
```
package main

import (
	"fmt"
	"log"
	"net/http"
	"time"
)

func main() {

	// parameter server
	server := http.Server{
		Addr:         "0.0.0.0:9000",
		Handler:      http.HandlerFunc(helloworld),
		ReadTimeout:  5 * time.Second,
		WriteTimeout: 5 * time.Second,
	}

	// mulai listening server
	if err := server.ListenAndServe(); err != nil {
		log.Fatalf("error: listening and serving: %s", err)
	}
}

// helloworld: basic http handler dengan response string hello world
func helloworld(w http.ResponseWriter, r *http.Request) {
	fmt.Fprint(w, "Hello World!")
}
```
- Listening server bisa dijalankan secara asynchronous melalui go routine. Dan untuk menangkap error yang terjadi digunakan channel.
```
package main

import (
	"fmt"
	"log"
	"net/http"
	"time"
)

func main() {

	// parameter server
	server := http.Server{
		Addr:         "0.0.0.0:9000",
		Handler:      http.HandlerFunc(helloworld),
		ReadTimeout:  5 * time.Second,
		WriteTimeout: 5 * time.Second,
	}

	serverErrors := make(chan error, 1)
	// mulai listening server
	go func() {
		log.Println("server listening on", server.Addr)
		serverErrors <- server.ListenAndServe()
	}()

	if err, ok := <-serverErrors; ok {
		log.Fatalf("error: listening and serving: %s", err)
	}
}

// helloworld: basic http handler dengan response string hello world
func helloworld(w http.ResponseWriter, r *http.Request) {
	fmt.Fprint(w, "Hello World!")
}
``` 