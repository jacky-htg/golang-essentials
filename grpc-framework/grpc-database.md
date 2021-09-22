# Database

* Kita sudah pernah mempelajari [database](../build-rest-api-framework/database.md)
* Update .env untuk menambahkan konfigurasi database 

```text
PORT = 7070
POSTGRES_HOST = localhost
POSTGRES_PORT = 5432
POSTGRES_USER = postgres
POSTGRES_PASSWORD = pass
POSTGRES_DB = drivers
```

* Buat file lib/database/postgres/postgres.go

```text
package postgres

import (
    "context"
    "database/sql"
    "fmt"
    "os"
    "strconv"
)

// Open database commection
func Open() (*sql.DB, error) {
    var db *sql.DB
    port, err := strconv.Atoi(os.Getenv("POSTGRES_PORT"))
    if err != nil {
        return db, err
    }

    return sql.Open("postgres",
        fmt.Sprintf(
            "host=%s port=%d user=%s password=%s dbname=%s sslmode=disable",
            os.Getenv("POSTGRES_HOST"), port, os.Getenv("POSTGRES_USER"),
            os.Getenv("POSTGRES_PASSWORD"), os.Getenv("POSTGRES_DB"),
        ),
    )
}

// StatusCheck returns nil if it can successfully talk to the database. It
// returns a non-nil error otherwise.
func StatusCheck(ctx context.Context, db *sql.DB) error {

    // Run a simple query to determine connectivity. The db has a "Ping" method
    // but it can false-positive when it was previously able to talk to the
    // database but the database has since gone away. Running this query forces a
    // round trip to the database.
    const q = `SELECT true`
    var tmp bool
    return db.QueryRowContext(ctx, q).Scan(&tmp)
}
```

* Buat file schema/migrate.go

```text
package schema

import (
    "database/sql"

    "github.com/GuiaBolso/darwin"
)

var migrations = []darwin.Migration{
    {
        Version:     1,
        Description: "Create drivers Table",
        Script: `
            CREATE TABLE public.drivers (
                id uuid NOT NULL,
                name varchar NOT NULL,
                phone varchar NOT NULL,
                licence_number varchar NOT NULL,
                company_id varchar NOT NULL,
                company_name varchar NOT NULL,
                is_deleted bool NOT NULL DEFAULT false,
                created timestamp(0) NOT NULL,
                created_by varchar NOT NULL,
                updated timestamp(0) NOT NULL,
                updated_by varchar NOT NULL,
                CONSTRAINT drivers_pk PRIMARY KEY (id)
            );
            CREATE UNIQUE INDEX drivers_phone ON public.drivers USING btree (phone);
        `,
    },
}

// Migrate attempts to bring the schema for db up to date with the migrations
// defined in this package.
func Migrate(db *sql.DB) error {
    driver := darwin.NewGenericDriver(db, darwin.PostgresDialect{})

    d := darwin.New(driver, migrations, nil)

    return d.Migrate()
}
```

* Buat file schema/seed.go

```text
package schema

import (
    "database/sql"
    "fmt"
)

// seeds is a string constant containing all of the queries needed to get the
// db seeded to a useful state for development.
//
// Using a constant in a .go file is an easy way to ensure the queries are part
// of the compiled executable and avoids pathing issues with the working
// directory. It has the downside that it lacks syntax highlighting and may be
// harder to read for some cases compared to using .sql files. You may also
// consider a combined approach using a tool like packr or go-bindata.
//
// Note that database servers besides PostgreSQL may not support running
// multiple queries as part of the same execution so this single large constant
// may need to be broken up.

// Seed runs the set of seed-data queries against db. The queries are ran in a
// transaction and rolled back if any fail.
func Seed(db *sql.DB, seeds ...string) error {

    tx, err := db.Begin()
    if err != nil {
        return err
    }

    for _, seed := range seeds {
        _, err = tx.Exec(seed)
        if err != nil {
            tx.Rollback()
            fmt.Println("error execute seed")
            return err
        }
    }

    return tx.Commit()
}
```

* Buat file cmd/cli.go

```text
package main

import (
    "flag"
    "fmt"
    "log"
    "os"

    "skeleton/config"
    "skeleton/lib/database/postgres"
    "skeleton/schema"

    _ "github.com/lib/pq"
)

func main() {
    config.Setup(".env")

    log := log.New(os.Stdout, "Skeleton : ", log.LstdFlags|log.Lmicroseconds|log.Lshortfile)
    if err := run(log); err != nil {
        log.Printf("error: shutting down: %s", err)
        os.Exit(1)
    }
}

func run(log *log.Logger) error {
    // =========================================================================
    // App Starting

    log.Printf("main : Started")
    defer log.Println("main : Completed")

    // =========================================================================

    // Start Database

    db, err := postgres.Open()
    if err != nil {
        return fmt.Errorf("connecting to db: %v", err)
    }
    defer db.Close()

    // Handle cli command
    flag.Parse()

    switch flag.Arg(0) {
    case "migrate":
        if err := schema.Migrate(db); err != nil {
            return fmt.Errorf("applying migrations: %v", err)
        }
        log.Println("Migrations complete")
        return nil

    case "seed":
        if err := schema.Seed(db); err != nil {
            return fmt.Errorf("seeding database: %v", err)
        }
        log.Println("Seed data complete")
        return nil
    }

    return nil
}
```

* Buat database drivers
* Jalankan `go run cmd/cli.go migrate` 
* Update file server.go untuk membuat koneksi database

```text
package main

import (
    "context"
    "database/sql"
    "log"
    "net"
    "os"
    "skeleton/config"
    "skeleton/lib/database/postgres"
    "skeleton/pb/drivers"
    "skeleton/pb/generic"

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
    grpcRoute(grpcServer, log, db)

    if err := grpcServer.Serve(lis); err != nil {
        log.Fatalf("failed to serve: %s", err)
        return
    }
    log.Print("serve grpc on port: " + os.Getenv("PORT"))

}
```

* Update server.go untuk mengaupdate roting dengan menginject db ke service

```text
func grpcRoute(grpcServer *grpc.Server, log *log.Logger, db *sql.DB) {
    driverServer := newDriverHandler(log, db)

    drivers.RegisterDriversServiceServer(grpcServer, driverServer)
}
```

* Update server.go service handler agar mempunyai proprety db

```text
type driverHandler struct {
    log *log.Logger
    db  *sql.DB
}

func newDriverHandler(log *log.Logger, db *sql.DB) *driverHandler {
    handler := new(driverHandler)
    handler.log = log
    handler.db = db
    return handler
}
```

* Update file server.go untuk membuat fungsi logError

```text
func logError(log *log.Logger, code codes.Code, err error) error {
    log.Print(err.Error())
    return status.Error(code, err.Error())
}
```

* Update file server.go untuk mengupdate fungsi List

```text
func (u *driverHandler) List(ctx context.Context, in *drivers.DriverListInput) (*drivers.Drivers, error) {
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
```

* Update file server.go untuk mengupdate fungsi Create

```text
func (u *driverHandler) Create(ctx context.Context, in *drivers.Driver) (*drivers.Driver, error) {
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
```

* Update file server.go untuk mengupdate fungsi Update

```text
func (u *driverHandler) Update(ctx context.Context, in *drivers.Driver) (*drivers.Driver, error) {
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
```

* Update file server.go untuk mengupdate fungsi Delete

```text
func (u *driverHandler) Delete(ctx context.Context, in *generic.Id) (*generic.BoolMessage, error) {
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
```

* Test create dengan perintah `grpcurl -plaintext -import-path ~/jackyhtg/skeleton/proto -proto ~/jackyhtg/skeleton/proto/drivers/driver_service.proto -d '{"name": "jacky", "phone": "08172221", "licence_number": "1234", "company_id": "UAT", "company_name": "Universal Alabama Tahoma"}' localhost:7070 skeleton.DriversService.Create`
* Tes list dengan perintah `grpcurl -import-path ~/jackyhtg/skeleton/proto -proto ~/jackyhtg/skeleton/proto/drivers/driver_service.proto -plaintext localhost:7070 skeleton.DriversService.List` 
* Tes delete denagn perintah `grpcurl -plaintext -import-path ~/jackyhtg/skeleton/proto -proto ~/jackyhtg/skeleton/proto/drivers/driver_service.proto -d '{"id":"3a36a71f-021c-4465-9fda-36699b320855"}' localhost:7070 skeleton.DriversService.Delete`

Ini adalah kode keseluruhan server.go

```text
package main

import (
    "context"
    "database/sql"
    "fmt"
    "log"
    "net"
    "os"
    "strconv"
    "strings"
    "time"

    "skeleton/config"
    "skeleton/lib/database/postgres"
    "skeleton/pb/drivers"
    "skeleton/pb/generic"

    "github.com/google/uuid"
    _ "github.com/lib/pq"
    "google.golang.org/grpc"
    "google.golang.org/grpc/codes"
    "google.golang.org/grpc/status"
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
    grpcRoute(grpcServer, log, db)

    if err := grpcServer.Serve(lis); err != nil {
        log.Fatalf("failed to serve: %s", err)
        return
    }
    log.Print("serve grpc on port: " + os.Getenv("PORT"))

}

func grpcRoute(grpcServer *grpc.Server, log *log.Logger, db *sql.DB) {
    driverServer := newDriverHandler(log, db)

    drivers.RegisterDriversServiceServer(grpcServer, driverServer)
}

type driverHandler struct {
    log *log.Logger
    db  *sql.DB
}

func newDriverHandler(log *log.Logger, db *sql.DB) *driverHandler {
    handler := new(driverHandler)
    handler.log = log
    handler.db = db
    return handler
}

func (u *driverHandler) List(ctx context.Context, in *drivers.DriverListInput) (*drivers.Drivers, error) {
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

func (u *driverHandler) Create(ctx context.Context, in *drivers.Driver) (*drivers.Driver, error) {
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

func (u *driverHandler) Update(ctx context.Context, in *drivers.Driver) (*drivers.Driver, error) {
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

func (u *driverHandler) Delete(ctx context.Context, in *generic.Id) (*generic.BoolMessage, error) {
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

