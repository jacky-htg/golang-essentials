# gRPC Client
- Dalam micro servoces, memanggil service lain adalah sebuah keniscayaan.
- Dengan asumsi semua service internal didevelop dengan grpc, maka kita perlu membuat panggilan grpc client.
- Misal kita akan memanggil service auth yang mempunyai file proto proto/auth/auth_service.proto

```
syntax = "proto3";
package skeleton;

option go_package = "skeleton/pb/auth;auth";

message LoginInput {
  string username = 1;
  string password = 2;
}

message TokenResponse {
  string token = 1;
}

service AuthService {
  rpc Login(LoginInput) returns (TokenResponse) {}
}
```

- jalankan `make gen`
- Buat lib grpc client lib/grpcclient/client.go

```
package grpcclient

import (
	"google.golang.org/grpc"
)

func Close(conn map[string]*grpc.ClientConn) {
	for _, c := range conn {
		c.Close()
	}
}

```

- Buat file grpcconn/client_conn.go

```
package grpcconn

import (
	"fmt"
	"os"
	"skeleton/lib/grpcclient"

	"google.golang.org/grpc"
)

func ClientConn() (map[string]*grpc.ClientConn, func(), error) {
	var conn map[string]*grpc.ClientConn

	authConn, err := grpc.Dial(os.Getenv("AUTH_SERVICE"), grpc.WithInsecure())
	if err != nil {
		return nil, func() {}, fmt.Errorf("create auth service connection: %v", err)
	}

	conn["AUTH"] = authConn

	return conn, func() { grpcclient.Close(conn) }, nil
}

```

- Kode di atas memanggil address auth service yang disimpan dalam env.
- Tambahkan env auth service address di file .env

```
PORT = 7070
POSTGRES_HOST = localhost
POSTGRES_PORT = 5432
POSTGRES_USER = postgres
POSTGRES_PASSWORD = pass
POSTGRES_DB = drivers
AUTH_SERVICE = localhost:5050
```

- Update file server.go untuk membuat koneksi grpc client dan menginject-nya ke routing agar diteruskan ke service yang sekiranya membutuhkan koneksi tersebut.

```
package main

import (
	"log"
	"net"
	"os"

	"skeleton/config"
	"skeleton/grpcconn"
	"skeleton/lib/database/postgres"
	"skeleton/route"

	_ "github.com/lib/pq"
	"google.golang.org/grpc"
)

func main() {
	config.Setup(".env")

	log := log.New(os.Stdout, "Skeleton : ", log.LstdFlags|log.Lmicroseconds|log.Lshortfile)

	db, err := postgres.Open()
	if err != nil {
		log.Fatalf("connecting to db: %v", err)
		return
	}
	log.Print("connecting to postgresql database")

	defer db.Close()

	// listen tcp port
	lis, err := net.Listen("tcp", ":"+os.Getenv("PORT"))
	if err != nil {
		log.Fatalf("failed to listen: %v", err)
		return
	}

	grpcServer := grpc.NewServer()

	clientConn, clientClose, err := grpcconn.ClientConn()
	if err != nil {
		log.Fatalf("failed to get grpc client connection: %v", err)
		return
	}
	defer clientClose()

	// routing grpc services
	route.GrpcRoute(grpcServer, log, db, clientConn)

	if err := grpcServer.Serve(lis); err != nil {
		log.Fatalf("failed to serve: %s", err)
		return
	}
	log.Print("serve grpc on port: " + os.Getenv("PORT"))

}

```

- Ubah file route/route.go untuk menambahkan dependecy injection koneksi grpc client kepada repository yang membutuhkan.

```
package route

import (
	"database/sql"
	"log"

	driverHandler "skeleton/domain/ddrivers/handler"
	driverRepo "skeleton/domain/ddrivers/repositories"
	driverUsecase "skeleton/domain/ddrivers/usecase"
	"skeleton/pb/auth"
	"skeleton/pb/drivers"

	"google.golang.org/grpc"
)

func GrpcRoute(grpcServer *grpc.Server, log *log.Logger, db *sql.DB, clientConnection map[string]*grpc.ClientConn) {
	driverServer := driverHandler.NewDriverHandler(
		driverUsecase.NewService(
			log,
			driverRepo.NewDriverRepo(db, log, auth.NewAuthServiceClient(clientConnection["AUTH"]))),
	)

	drivers.RegisterDriversServiceServer(grpcServer, driverServer)
}
```

- Ubah file repository yang membutuhkan koneksi grpc client. misal file domain/ddrivers/repositories/repo.go

```
package repositories

import (
	"database/sql"
	"log"
	"skeleton/domain/ddrivers"
	"skeleton/pb/auth"
	"skeleton/pb/drivers"
)

type repo struct {
	db         *sql.DB
	log        *log.Logger
	pb         drivers.Driver
	authClient auth.AuthServiceClient
}

func NewDriverRepo(db *sql.DB, log *log.Logger, authClient auth.AuthServiceClient) ddrivers.DriverRepoInterface {
	return &repo{
		db:         db,
		log:        log,
		authClient: authClient,
	}
}

func (u *repo) GetPb() *drivers.Driver {
	return &u.pb
}

func (u *repo) SetPb(in *drivers.Driver) {
	if len(in.Id) > 0 {
		u.pb.Id = in.Id
	}
	if len(in.Name) > 0 {
		u.pb.Name = in.Name
	}
	if len(in.Phone) > 0 {
		u.pb.Phone = in.Phone
	}
	if len(in.LicenceNumber) > 0 {
		u.pb.LicenceNumber = in.LicenceNumber
	}
	if len(in.CompanyId) > 0 {
		u.pb.CompanyId = in.CompanyId
	}
	if len(in.CompanyName) > 0 {
		u.pb.CompanyName = in.CompanyName
	}
	u.pb.IsDelete = in.IsDelete
	if len(in.Created) > 0 {
		u.pb.Created = in.Created
	}
	if len(in.CreatedBy) > 0 {
		u.pb.CreatedBy = in.CreatedBy
	}
	if len(in.Updated) > 0 {
		u.pb.Updated = in.Updated
	}
	if len(in.UpdatedBy) > 0 {
		u.pb.UpdatedBy = in.UpdatedBy
	}
}

```