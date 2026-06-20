# Config

* kita sudah mempelajari config di materi [Configuration](../build-rest-api-framework/configuration.md)
* Kita akan praktekan dengan mengubah port menjadi env.
* Buat file config/config.go

```go
package config

import (
    "io/ioutil"
    "os"
    "strings"
)

//Setup environment from file .env
func Setup(file string) error {
    data, err := ioutil.ReadFile(file)
    if err != nil {
        return err
    }

    datas := strings.Split(string(data), "\n")
    for _, env := range datas {
        e := strings.Split(env, "=")
        if len(e) >= 2 {
            os.Setenv(strings.TrimSpace(e[0]), strings.TrimSpace(strings.Join(e[1:], "=")))
        }
    }

    return nil
}
```

* Buat file .env

```text
PORT=7070
```

* Update file server.go untuk menambahkan import "skeleton/config"
* masih di file server.go pada fungsi main tambahkan di baris paling atas `config.Setup(".env")`
* semua port yang dihardcode ganti dengan `os.Getenv("PORT")`

```go
package main

import (
    "context"
    "log"
    "net"
    "os"
    "skeleton/config"
    "skeleton/pb/drivers"
    "skeleton/pb/generic"

    "google.golang.org/grpc"
)

func main() {
    config.Setup(".env")

    log := log.New(os.Stdout, "Essentials : ", log.LstdFlags|log.Lmicroseconds|log.Lshortfile)

    // listen tcp port
    lis, err := net.Listen("tcp", ":"+os.Getenv("PORT"))
    if err != nil {
        log.Fatalf("failed to listen: %v", err)
        return
    }

    grpcServer := grpc.NewServer()

    // routing grpc services
    grpcRoute(grpcServer, log)

    if err := grpcServer.Serve(lis); err != nil {
        log.Fatalf("failed to serve: %s", err)
        return
    }
    log.Print("serve grpc on port: " + os.Getenv("PORT"))

}

func grpcRoute(grpcServer *grpc.Server, log *log.Logger) {
    driverServer := newDriverHandler(log)

    drivers.RegisterDriversServiceServer(grpcServer, driverServer)
}

type driverHandler struct {
    log *log.Logger
}

func newDriverHandler(log *log.Logger) *driverHandler {
    handler := new(driverHandler)
    handler.log = log
    return handler
}

func (u *driverHandler) List(ctx context.Context, in *drivers.DriverListInput) (*drivers.Drivers, error) {
    return &drivers.Drivers{}, nil
}

func (u *driverHandler) Create(ctx context.Context, in *drivers.Driver) (*drivers.Driver, error) {
    return in, nil
}

func (u *driverHandler) Update(ctx context.Context, in *generic.Id) (*drivers.Driver, error) {
    return &drivers.Driver{}, nil
}

func (u *driverHandler) Delete(ctx context.Context, in *generic.Id) (*generic.BoolMessage, error) {
    return &generic.BoolMessage{}, nil
}
```

