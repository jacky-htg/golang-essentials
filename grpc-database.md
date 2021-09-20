# Database
- Kita sudah pernah mempelajari [database](./database.md)
- Update .env untuk menambahkan konfigurasi database 

```
PORT = 7070
POSTGRES_HOST = localhost
POSTGRES_PORT = 5432
POSTGRES_USER = postgres
POSTGRES_PASSWORD = pass
POSTGRES_DB = drivers
```

- Buat file lib/datbase/postgres/postgres.go

```
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

- Buat file schema/migrate.go

```
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

- Buat file schema/seed.go

```
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

- Buat file cmd/cli.go

```
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

- Buat database drivers
- Jalankan go run cmd/cli.go migrate 
- Update file server.go untuk membuat koneksi database

```
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

func grpcRoute(grpcServer *grpc.Server, log *log.Logger, db *sql.DB) {
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