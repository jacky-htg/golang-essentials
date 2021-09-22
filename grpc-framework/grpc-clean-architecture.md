# Clean Architecture

* kita sudah pernah mempelajari [clean arcitecture](../build-rest-api-framework/clean-architecture.md)
* kali ini kita akan menggunakan konsep domain
* structure folder sebagai berikut

```text
---- [domain]
     ---- [ddrivers]
          ---- [handler]
               grpc.go
          ---- [repositories]
               repo.go
          ---- [usecase]
               usecase.go
               otherfile.go
          ---- [validation]
               validationfile.go
          repository_interface.go
          usecase_interface.go
          validation_interface.go
```

* Kita akan memecah file domain/ddrivers/handler.go menjadi mengikuti structur folder di atas.

## Helper

* untuk fungsi-fungsi pendukung akan dikelompokkan ke dalam folder lib/helper
* Buat file lib/helper/error\_ctx.go

```text
package helper

import (
    "context"

    "google.golang.org/grpc/codes"
    "google.golang.org/grpc/status"
)

func ContextError(ctx context.Context) error {
    switch ctx.Err() {
    case context.Canceled:
        return status.Error(codes.Canceled, context.Canceled.Error())
    case context.DeadlineExceeded:
        return status.Error(codes.DeadlineExceeded, context.DeadlineExceeded.Error())
    default:
        return nil
    }
}
```

## Repository

* repository adalah kode-kode yang mengakses transaksi database
* Buat file domain/ddrivers/repository\_interface.go

```text
package ddrivers

import (
    "context"
    "skeleton/pb/drivers"
    "skeleton/pb/generic"
)

type DriverRepoInterface interface {
    Find(ctx context.Context, id string) error
    FindAll(ctx context.Context, in *drivers.DriverListInput) (*drivers.Drivers, error)
    Create(ctx context.Context) error
    Update(ctx context.Context) error
    Delete(ctx context.Context, in *generic.Id) error
    GetPb() *drivers.Driver
    SetPb(*drivers.Driver)
}
```

* Buat folder domain/ddrivers/repositories. Semua file yang mengimplementasikan repository interface akan dibuat dalam filder ini. 
* Buat file domain/ddrivers/repositories/repo.go

```text
package repositories

import (
    "database/sql"
    "log"
    "skeleton/domain/ddrivers"
    "skeleton/pb/drivers"
)

type repo struct {
    db  *sql.DB
    log *log.Logger
    pb  drivers.Driver
}

func NewDriverRepo(db *sql.DB, log *log.Logger) ddrivers.DriverRepoInterface {
    return &repo{
        db:  db,
        log: log,
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

* Buat file domain/ddrivers/repositories/find\_all.go

```text
package repositories

import (
    "context"
    "fmt"
    "skeleton/lib/helper"
    "skeleton/pb/drivers"
    "skeleton/pb/generic"
    "strconv"
    "strings"

    "google.golang.org/grpc/codes"
    "google.golang.org/grpc/status"
)

func (u *repo) FindAll(ctx context.Context, in *drivers.DriverListInput) (*drivers.Drivers, error) {
    select {
    case <-ctx.Done():
        return nil, helper.ContextError(ctx)
    default:
    }

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
        u.log.Println(err.Error())
        return nil, status.Error(codes.Internal, err.Error())
    }
    defer rows.Close()

    for rows.Next() {
        var obj drivers.Driver
        err = rows.Scan(&obj.Id, &obj.Name, &obj.Phone, &obj.LicenceNumber, &obj.CompanyId, &obj.CompanyName)
        if err != nil {
            u.log.Println(err.Error())
            return nil, status.Error(codes.Internal, err.Error())
        }

        out.Driver = append(out.Driver, &obj)
    }

    if rows.Err() != nil {
        u.log.Println(rows.Err().Error())
        return nil, status.Error(codes.Internal, rows.Err().Error())
    }

    return out, nil
}
```

* Buat file domain/ddrivers/repositories/find\_driver\_by\_id.go

```text
package repositories

import (
    "context"
    "skeleton/lib/helper"

    "google.golang.org/grpc/codes"
    "google.golang.org/grpc/status"
)

func (u *repo) Find(ctx context.Context, id string) error {
    select {
    case <-ctx.Done():
        return helper.ContextError(ctx)
    default:
    }

    query := `
        SELECT id, name, phone, licence_number, company_id, company_name 
        FROM drivers WHERE id = $1 AND is_deleted = false
    `

    err := u.db.QueryRowContext(ctx, query, id).Scan(
        &u.pb.Id, &u.pb.Name, &u.pb.LicenceNumber, &u.pb.CompanyId, &u.pb.CompanyName)

    if err != nil {
        u.log.Println(err.Error())
        return status.Error(codes.Internal, err.Error())
    }

    return nil
}
```

* Buat file domain/ddrivers/repositories/create\_driver.go

```text
package repositories

import (
    "context"
    "skeleton/lib/helper"
    "time"

    "github.com/google/uuid"
    "google.golang.org/grpc/codes"
    "google.golang.org/grpc/status"
)

func (u *repo) Create(ctx context.Context) error {
    select {
    case <-ctx.Done():
        return helper.ContextError(ctx)
    default:
    }

    query := `
        INSERT INTO drivers (
            id, name, phone, licence_number, company_id, company_name, created, created_by, updated, updated_by)
        VALUES ($1, $2, $3 ,$4, $5, $6, $7, $8, $9, $10)
    `
    u.pb.Id = uuid.New().String()
    now := time.Now().Format("2006-01-02 15:04:05.000000")
    _, err := u.db.ExecContext(ctx, query,
        u.pb.Id, u.pb.Name, u.pb.Phone, u.pb.LicenceNumber, u.pb.CompanyId, u.pb.CompanyName,
        now, u.pb.CreatedBy, now, u.pb.UpdatedBy)

    if err != nil {
        u.log.Println(err.Error())
        return status.Error(codes.Internal, err.Error())
    }

    return nil
}
```

* Buat file domain/ddrivers/repositories/update\_driver\_by\_id.go

```text
package repositories

import (
    "context"
    "skeleton/lib/helper"
    "time"

    "google.golang.org/grpc/codes"
    "google.golang.org/grpc/status"
)

func (u *repo) Update(ctx context.Context) error {
    select {
    case <-ctx.Done():
        return helper.ContextError(ctx)
    default:
    }

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
        u.pb.Name, u.pb.Phone, u.pb.LicenceNumber, now, u.pb.UpdatedBy, u.pb.Id)

    if err != nil {
        u.log.Println(err.Error())
        return status.Error(codes.Internal, err.Error())
    }

    return nil
}
```

* Buat file domain/ddrivers/repositories/delete\_driver\_by\_id.go

```text
package repositories

import (
    "context"
    "skeleton/lib/helper"
    "skeleton/pb/generic"

    "google.golang.org/grpc/codes"
    "google.golang.org/grpc/status"
)

func (u *repo) Delete(ctx context.Context, in *generic.Id) error {
    select {
    case <-ctx.Done():
        return helper.ContextError(ctx)
    default:
    }

    query := `
        UPDATE drivers 
        SET is_deleted = true
        WHERE id = $1
    `
    _, err := u.db.ExecContext(ctx, query, in.Id)

    if err != nil {
        u.log.Println(err.Error())
        return status.Error(codes.Internal, err.Error())
    }

    return nil
}
```

## Validation

* Setiap request perlu divalidasi
* Buat file domain/ddrivers/validation\_interface.go 

```text
package ddrivers

import (
    "context"
    "skeleton/pb/drivers"
)

type DriverValidationInterface interface {
    Create(ctx context.Context, id *drivers.Driver) error
    Update(ctx context.Context, id *drivers.Driver) error
    Delete(ctx context.Context, id string) error
}
```

* Untuk kemudahan pengelolaan kode, semua implementasi validasi akan dimasukkan dalam folder domain/drivers/validation
* Buat file domain/ddrivers/validation/driover\_validation.go

```text
package validation

import (
    "log"
    "skeleton/domain/ddrivers"
)

type driverValidation struct {
    log        *log.Logger
    driverRepo ddrivers.DriverRepoInterface
}

func NewValidation(log *log.Logger, driverRepo ddrivers.DriverRepoInterface) ddrivers.DriverValidationInterface {
    return &driverValidation{
        log:        log,
        driverRepo: driverRepo,
    }
}
```

* Buat file domain/ddrivers/validation/create\_driver\_validation.go

```text
package validation

import (
    "context"
    "skeleton/lib/helper"
    "skeleton/pb/drivers"

    "google.golang.org/grpc/codes"
    "google.golang.org/grpc/status"
)

func (u *driverValidation) Create(ctx context.Context, in *drivers.Driver) error {
    select {
    case <-ctx.Done():
        return helper.ContextError(ctx)
    default:
    }

    if len(in.Name) == 0 {
        u.log.Println("please supply valid name")
        return status.Error(codes.InvalidArgument, "please supply valid name")
    }

    if len(in.Phone) == 0 {
        u.log.Println("please supply valid phone")
        return status.Error(codes.InvalidArgument, "please supply valid phone")
    }

    if len(in.CompanyId) == 0 {
        u.log.Println("please supply valid company id")
        return status.Error(codes.InvalidArgument, "please supply valid company id")
    }

    if len(in.CompanyName) == 0 {
        u.log.Println("please supply valid company name")
        return status.Error(codes.InvalidArgument, "please supply valid company name")
    }

    if len(in.LicenceNumber) == 0 {
        u.log.Println("please supply valid licence number")
        return status.Error(codes.InvalidArgument, "please supply valid licence number")
    }

    return nil
}
```

* Buat file domain/ddrivers/validation/update\_driver\_validation.go

```text
package validation

import (
    "context"
    "skeleton/lib/helper"
    "skeleton/pb/drivers"

    "google.golang.org/grpc/codes"
    "google.golang.org/grpc/status"
)

func (u *driverValidation) Create(ctx context.Context, in *drivers.Driver) error {
    select {
    case <-ctx.Done():
        return helper.ContextError(ctx)
    default:
    }

    if len(in.Name) == 0 {
        u.log.Println("please supply valid name")
        return status.Error(codes.InvalidArgument, "please supply valid name")
    }

    if len(in.Phone) == 0 {
        u.log.Println("please supply valid phone")
        return status.Error(codes.InvalidArgument, "please supply valid phone")
    }

    if len(in.CompanyId) == 0 {
        u.log.Println("please supply valid company id")
        return status.Error(codes.InvalidArgument, "please supply valid company id")
    }

    if len(in.CompanyName) == 0 {
        u.log.Println("please supply valid company name")
        return status.Error(codes.InvalidArgument, "please supply valid company name")
    }

    if len(in.LicenceNumber) == 0 {
        u.log.Println("please supply valid licence number")
        return status.Error(codes.InvalidArgument, "please supply valid licence number")
    }

    return nil
}
```

* Buat file domain/ddrivers/validation/delete\_driver/validation.fo

```text
package validation

import (
    "context"
    "skeleton/lib/helper"
)

func (u *driverValidation) Delete(ctx context.Context, id string) error {
    select {
    case <-ctx.Done():
        return helper.ContextError(ctx)
    default:
    }

    err := u.driverRepo.Find(ctx, id)
    if err != nil {
        return err
    }

    return nil
}
```

## Usecase

* usacase digunakan untuk menghandle logic. usecase yang akan memanggil validation maupun repository sekiranya logic membutuhkan hal tersebut.
* Buat file usecase\_interface.go

```text
package ddrivers

import (
    "context"
    "skeleton/pb/drivers"
    "skeleton/pb/generic"
)

type DriverUsecaseInterface interface {
    List(ctx context.Context, in *drivers.DriverListInput) (*drivers.Drivers, error)
    Create(ctx context.Context, in *drivers.Driver) (*drivers.Driver, error)
    Update(ctx context.Context, in *drivers.Driver) (*drivers.Driver, error)
    Delete(ctx context.Context, in *generic.Id) (*generic.BoolMessage, error)
}
```

* Buat folder usecase untuk menyimpan seluruh fiule implementasi usecase interface.
* Buat file domain/ddrivers/usecase/usecase.go

```text
package usecase

import (
    "log"
    "skeleton/domain/ddrivers"
)

type service struct {
    log        *log.Logger
    driverRepo ddrivers.DriverRepoInterface
}

func NewService(log *log.Logger, driverRepo ddrivers.DriverRepoInterface) ddrivers.DriverUsecaseInterface {
    return &service{
        log:        log,
        driverRepo: driverRepo,
    }
}
```

* Buat file domain/ddrivers/usecase/create\_driver\_usecase.go

```text
package usecase

import (
    "context"
    "skeleton/domain/ddrivers/validation"
    "skeleton/lib/helper"
    "skeleton/pb/drivers"
)

func (u *service) Create(ctx context.Context, in *drivers.Driver) (*drivers.Driver, error) {
    select {
    case <-ctx.Done():
        return nil, helper.ContextError(ctx)
    default:
    }

    dValidation := validation.NewValidation(u.log, u.driverRepo)
    err := dValidation.Create(ctx, in)
    if err != nil {
        return nil, err
    }

    u.driverRepo.SetPb(in)

    err = u.driverRepo.Create(ctx)
    if err != nil {
        return nil, err
    }

    return u.driverRepo.GetPb(), nil
}
```

* Buat file domain/ddrivers/usecase/list\_driver\_usecase.go

```text
package usecase

import (
    "context"
    "skeleton/lib/helper"
    "skeleton/pb/drivers"
)

func (u *service) List(ctx context.Context, in *drivers.DriverListInput) (*drivers.Drivers, error) {
    select {
    case <-ctx.Done():
        return nil, helper.ContextError(ctx)
    default:
    }

    return u.driverRepo.FindAll(ctx, in)
}
```

* Buat file domain/ddrivers/usecase/update\_driver\_usecase.go

```text
package usecase

import (
    "context"
    "skeleton/domain/ddrivers/validation"
    "skeleton/lib/helper"
    "skeleton/pb/drivers"
)

func (u *service) Update(ctx context.Context, in *drivers.Driver) (*drivers.Driver, error) {
    select {
    case <-ctx.Done():
        return nil, helper.ContextError(ctx)
    default:
    }

    dValidation := validation.NewValidation(u.log, u.driverRepo)
    err := dValidation.Update(ctx, in)
    if err != nil {
        return nil, err
    }

    u.driverRepo.SetPb(in)

    err = u.driverRepo.Update(ctx)
    if err != nil {
        return nil, err
    }

    return u.driverRepo.GetPb(), nil
}
```

* Buat file domain.ddrivers/usecase/delete\_driver\_usecase.go

```text
package usecase

import (
    "context"
    "skeleton/domain/ddrivers/validation"
    "skeleton/lib/helper"
    "skeleton/pb/generic"
)

func (u *service) Delete(ctx context.Context, in *generic.Id) (*generic.BoolMessage, error) {
    select {
    case <-ctx.Done():
        return nil, helper.ContextError(ctx)
    default:
    }

    dValidation := validation.NewValidation(u.log, u.driverRepo)
    err := dValidation.Delete(ctx, in.Id)
    if err != nil {
        return nil, err
    }

    err = u.driverRepo.Delete(ctx, in)
    if err != nil {
        return nil, err
    }

    return &generic.BoolMessage{IsTrue: true}, nil
}
```

## Perbarui Handler

* handler merupakan endpoint service.
* handler mengimpolementasikan seluruh seluruh funhgsi dari interface DomainServiceServer
* Buat file domain/ddrivers/handler/grpc.go

```text
package handler

import (
    "context"
    "skeleton/domain/ddrivers"
    "skeleton/pb/drivers"
    "skeleton/pb/generic"
)

type DriverHandler struct {
    usecase ddrivers.DriverUsecaseInterface
}

func NewDriverHandler(usecase ddrivers.DriverUsecaseInterface) *DriverHandler {
    handler := new(DriverHandler)
    handler.usecase = usecase
    return handler
}

func (u *DriverHandler) List(ctx context.Context, in *drivers.DriverListInput) (*drivers.Drivers, error) {
    return u.usecase.List(ctx, in)
}

func (u *DriverHandler) Create(ctx context.Context, in *drivers.Driver) (*drivers.Driver, error) {
    return u.usecase.Create(ctx, in)
}

func (u *DriverHandler) Update(ctx context.Context, in *drivers.Driver) (*drivers.Driver, error) {
    return u.usecase.Update(ctx, in)
}

func (u *DriverHandler) Delete(ctx context.Context, in *generic.Id) (*generic.BoolMessage, error) {
    return u.usecase.Delete(ctx, in)
}
```

## Perbarui Routing

* Ubah file route/route.go

```text
package route

import (
    "database/sql"
    "log"

    driverHandler "skeleton/domain/ddrivers/handler"
    driverRepo "skeleton/domain/ddrivers/repositories"
    driverUsecase "skeleton/domain/ddrivers/usecase"
    "skeleton/pb/drivers"

    "google.golang.org/grpc"
)

func GrpcRoute(grpcServer *grpc.Server, log *log.Logger, db *sql.DB) {
    driverServer := driverHandler.NewDriverHandler(
        driverUsecase.NewService(log, driverRepo.NewDriverRepo(db, log)),
    )

    drivers.RegisterDriversServiceServer(grpcServer, driverServer)
}
```

