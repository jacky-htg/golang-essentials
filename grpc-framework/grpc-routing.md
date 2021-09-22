# Routing

* saat ini semua kode ada dalam 1 file server.go
* pecah kode driverHandler dalam file domain/ddrivers/handler.go
* karena akan kita export, pastikan type dan fungsi dubah dengan awalan huruf besar

```go
package ddrivers

import (
    "context"
    "database/sql"
    "fmt"
    "log"
    "skeleton/pb/drivers"
    "skeleton/pb/generic"
    "strconv"
    "strings"
    "time"

    "github.com/google/uuid"
    "google.golang.org/grpc/codes"
    "google.golang.org/grpc/status"
)

type DriverHandler struct {
    log *log.Logger
    db  *sql.DB
}

func NewDriverHandler(log *log.Logger, db *sql.DB) *DriverHandler {
    handler := new(DriverHandler)
    handler.log = log
    handler.db = db
    return handler
}

func (u *DriverHandler) List(ctx context.Context, in *drivers.DriverListInput) (*drivers.Drivers, error) {
    out := &drivers.Drivers{}
    query := `SELECT id, name, phone, licence_number, company_id, company_name FROM drivers`
    where := []string{"is_deleted = false"}
    paramQueries := []interface{}{}

    if len(in.Ids) > 0 {
        orWhere := []string{}
        for _, id := range in.Ids {
            paramQueries = append(paramQueries, id)
            orWhere = append(orWhere, fmt.Sprintf("id = %d", len(paramQueries)))
        }
        if len(orWhere) > 0 {
            where = append(where, "("+strings.Join(orWhere, " OR ")+")")
        }
    }

    if len(in.CompanyIds) > 0 {
        orWhere := []string{}
        for _, id := range in.CompanyIds {
            paramQueries = append(paramQueries, id)
            orWhere = append(orWhere, fmt.Sprintf("company_id = %d", len(paramQueries)))
        }
        if len(orWhere) > 0 {
            where = append(where, "("+strings.Join(orWhere, " OR ")+")")
        }
    }

    if len(in.LicenceNumbers) > 0 {
        orWhere := []string{}
        for _, licenceNumber := range in.LicenceNumbers {
            paramQueries = append(paramQueries, licenceNumber)
            orWhere = append(orWhere, fmt.Sprintf("licence_number = %d", len(paramQueries)))
        }
        if len(orWhere) > 0 {
            where = append(where, "("+strings.Join(orWhere, " OR ")+")")
        }
    }

    if len(in.Names) > 0 {
        orWhere := []string{}
        for _, name := range in.Names {
            paramQueries = append(paramQueries, name)
            orWhere = append(orWhere, fmt.Sprintf("name = %d", len(paramQueries)))
        }
        if len(orWhere) > 0 {
            where = append(where, "("+strings.Join(orWhere, " OR ")+")")
        }
    }

    if len(in.Phones) > 0 {
        orWhere := []string{}
        for _, phone := range in.Phones {
            paramQueries = append(paramQueries, phone)
            orWhere = append(orWhere, fmt.Sprintf("phone = %d", len(paramQueries)))
        }
        if len(orWhere) > 0 {
            where = append(where, "("+strings.Join(orWhere, " OR ")+")")
        }
    }

    if in.Pagination == nil {
        in.Pagination = &generic.Pagination{}
    }

    if len(in.Pagination.Keyword) > 0 {
        orWhere := []string{}

        paramQueries = append(paramQueries, in.Pagination.Keyword)
        orWhere = append(orWhere, fmt.Sprintf("name = %d", len(paramQueries)))

        paramQueries = append(paramQueries, in.Pagination.Keyword)
        orWhere = append(orWhere, fmt.Sprintf("phone = %d", len(paramQueries)))

        paramQueries = append(paramQueries, in.Pagination.Keyword)
        orWhere = append(orWhere, fmt.Sprintf("licence_number = %d", len(paramQueries)))

        paramQueries = append(paramQueries, in.Pagination.Keyword)
        orWhere = append(orWhere, fmt.Sprintf("company_name = %d", len(paramQueries)))

        if len(orWhere) > 0 {
            where = append(where, "("+strings.Join(orWhere, " OR ")+")")
        }
    }

    if len(in.Pagination.Sort) > 0 {
        in.Pagination.Sort = strings.ToLower(in.Pagination.Sort)
        if in.Pagination.Sort != "asc" {
            in.Pagination.Sort = "desc"
        }
    } else {
        in.Pagination.Sort = "desc"
    }

    if len(in.Pagination.Order) > 0 {
        in.Pagination.Order = strings.ToLower(in.Pagination.Order)
        if !(in.Pagination.Order == "id" ||
            in.Pagination.Order == "name" ||
            in.Pagination.Order == "phone" ||
            in.Pagination.Order == "licence_number" ||
            in.Pagination.Order == "company_id" ||
            in.Pagination.Order == "company_name") {
            in.Pagination.Order = "id"
        }
    } else {
        in.Pagination.Order = "id"
    }

    if in.Pagination.Limit <= 0 {
        in.Pagination.Limit = 10
    }

    if in.Pagination.Offset <= 0 {
        in.Pagination.Offset = 0
    }

    if len(where) > 0 {
        query += " WHERE " + strings.Join(where, " AND ")
    }

    query += " ORDER BY " + in.Pagination.Order + " " + in.Pagination.Sort
    query += " LIMIT " + strconv.Itoa(int(in.Pagination.Limit))
    query += " OFFSET " + strconv.Itoa(int(in.Pagination.Offset))

    rows, err := u.db.QueryContext(ctx, query, paramQueries...)
    if err != nil {
        return out, logError(u.log, codes.Internal, err)
    }
    defer rows.Close()

    for rows.Next() {
        var obj drivers.Driver
        err = rows.Scan(&obj.Id, &obj.Name, &obj.Phone, &obj.LicenceNumber, &obj.CompanyId, &obj.CompanyName)
        if err != nil {
            return out, logError(u.log, codes.Internal, err)
        }

        out.Driver = append(out.Driver, &obj)
    }

    if rows.Err() != nil {
        return out, logError(u.log, codes.Internal, rows.Err())
    }

    return out, nil
}

func (u *DriverHandler) Create(ctx context.Context, in *drivers.Driver) (*drivers.Driver, error) {
    query := `
        INSERT INTO drivers (
            id, name, phone, licence_number, company_id, company_name, created, created_by, updated, updated_by)
        VALUES ($1, $2, $3 ,$4, $5, $6, $7, $8, $9, $10)
    `
    in.Id = uuid.New().String()
    now := time.Now().Format("2006-01-02 15:04:05.000000")
    _, err := u.db.ExecContext(ctx, query,
        in.Id, in.Name, in.Phone, in.LicenceNumber, in.CompanyId, in.CompanyName, now, "jaka", now, "jaka")

    if err != nil {
        return &drivers.Driver{}, logError(u.log, codes.Internal, err)
    }

    return in, nil
}

func (u *DriverHandler) Update(ctx context.Context, in *drivers.Driver) (*drivers.Driver, error) {
    query := `
        UPDATE drivers 
        SET name = $1, 
                phone = $2, 
                licence_number = $3, 
                updated = $4, 
                updated_by = $5
        WHERE id = $6
    `
    now := time.Now().Format("2006-01-02 15:04:05.000000")
    _, err := u.db.ExecContext(ctx, query,
        in.Name, in.Phone, in.LicenceNumber, now, "jaka", in.Id)

    if err != nil {
        return &drivers.Driver{}, logError(u.log, codes.Internal, err)
    }

    return in, nil
}

func (u *DriverHandler) Delete(ctx context.Context, in *generic.Id) (*generic.BoolMessage, error) {
    query := `
        UPDATE drivers 
        SET is_deleted = true
        WHERE id = $1
    `
    _, err := u.db.ExecContext(ctx, query, in.Id)

    if err != nil {
        return &generic.BoolMessage{IsTrue: false}, logError(u.log, codes.Internal, err)
    }

    return &generic.BoolMessage{IsTrue: true}, nil
}

func logError(log *log.Logger, code codes.Code, err error) error {
    log.Print(err.Error())
    return status.Error(code, err.Error())
}
```

* pecah kode routing dalam file route/route.go

```go
package route

import (
    "database/sql"
    "log"
    "skeleton/domain/ddrivers"
    "skeleton/pb/drivers"

    "google.golang.org/grpc"
)

func GrpcRoute(grpcServer *grpc.Server, log *log.Logger, db *sql.DB) {
    driverServer := ddrivers.NewDriverHandler(log, db)

    drivers.RegisterDriversServiceServer(grpcServer, driverServer)
}
```

* Ubah file server.go

```go
package main

import (
    "log"
    "net"
    "os"

    "skeleton/config"
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

    // routing grpc services
    route.GrpcRoute(grpcServer, log, db)

    if err := grpcServer.Serve(lis); err != nil {
        log.Fatalf("failed to serve: %s", err)
        return
    }
    log.Print("serve grpc on port: " + os.Getenv("PORT"))

}
```

