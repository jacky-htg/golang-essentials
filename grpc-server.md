# gRPC Server
- Buat file server.go

```
package main

import (
	"context"
	"log"
	"net"
	"os"
	"skeleton/pb/drivers"
	"skeleton/pb/generic"

	"google.golang.org/grpc"
)

func main() {

	log := log.New(os.Stdout, "grpc skeleton : ", log.LstdFlags|log.Lmicroseconds|log.Lshortfile)

	// listen tcp port
	lis, err := net.Listen("tcp", ":7070")
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
	log.Print("serve grpc on port: 7070")

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

- Buat grpc server `grpcServer := grpc.NewServer()`
- grpcRoute() untuk handling routing grpc
- Buat struct driverHandler yang mengimplementasikan seluruh interface DriverService protobuf
- jalankan `go run server.go`

## call grpc dengan grpc client
- ada banyak tool grpc client. ada yang berbasis gui seperti wombat maupun yang berbasis cli seperti grpcurl
- https://github.com/fullstorydev/grpcurl
- setelah instal grpcurl, call grpc list driver dengan perintah : `grpcurl -import-path ~/jackyhtg/skeleton/proto -proto ~/jackyhtg/skeleton/proto/drivers/driver_service.proto -plaintext localhost:7070 skeleton.DriversService.List`
- call grpc create driver
`grpcurl -plaintext -import-path ~/jackyhtg/skeleton/proto -proto ~/jackyhtg/skeleton/proto/drivers/driver_service.proto -d '{"name": "jacky", "phone": "08172221", "licence_number": "1234", "company_id": "UAT", "company_name": "Universal Alabama Tahoma"}' localhost:7070 skeleton.DriversService.Create`

