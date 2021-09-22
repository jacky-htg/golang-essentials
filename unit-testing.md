# Unit Testing

Unit testing adalah suatu tes untuk mengecek sebuah unit atu fungsi berjalan dengan baik atau tidak. Untuk pengetesan, baik pengetesan unit maupun pengetesan API, kita akan menggunakan database tersendiri yang kontainer-nya akan dicreate saat awal pengetesan dan di-drop setelah pengetesan berakhir. Proses create dan drop kontainer ini menggunakan perintah docker.

* Buat file libraries/database/databasetest/docker.go untuk mengelola perintah start dan stop kontainer mysql menggunakan docker.

```text
package databasetest

import (
    "bytes"
    "os/exec"
    "testing"
)

// StartContainer runs a mysql container to execute commands.
func StartContainer(t *testing.T) {
    t.Helper()

    cmd := exec.Command("docker", "run", "-d", "--name", "rebel_mysql", "--publish", "33060:3306", "--env", "MYSQL_ROOT_PASSWORD=1234", "--env", "MYSQL_DATABASE=rebel_db", "mysql:8")
    var out bytes.Buffer
    cmd.Stdout = &out
    if err := cmd.Run(); err != nil {
        t.Fatalf("could not start docker : %v", err)
    }

}

// StopContainer stops and removes the specified container.
func StopContainer(t *testing.T) {
    t.Helper()

    if err := exec.Command("docker", "container", "rm", "-f", "rebel_mysql").Run(); err != nil {
        t.Fatalf("could not stop mysql container: %v", err)
    }
}
```

* Buat file tests/app\_test.go to menyediakan services utama. Setiap kali dilakukan pengetesan maka akan memanggil service uatama ini.

```text
package tests

import (
    "database/sql"
    "essentials/libraries/database/databasetest"
    "essentials/schema"
    "os"
    "testing"
    "time"
)

// NewUnit creates a test database inside a Docker container. It creates the
// required table structure but the database is otherwise empty.
//
// It does not return errors as this intended for testing only. Instead it will
// call Fatal on the provided testing.T if anything goes wrong.
//
// It returns the database to use as well as a function to call at the end of
// the test.
func NewUnit(t *testing.T) (*sql.DB, func()) {
    t.Helper()

    databasetest.StartContainer(t)

    db, err := sql.Open("mysql", "root:1234@tcp(localhost:33060)/rebel_db?parseTime=true")
    if err != nil {
        t.Fatalf("opening database connection: %v", err)
    }

    t.Log("waiting for database to be ready")

    // Wait for the database to be ready. Wait 100ms longer between each attempt.
    // Do not try more than 20 times.
    var pingError error
    maxAttempts := 20
    for attempts := 1; attempts <= maxAttempts; attempts++ {
        pingError = db.Ping()
        if pingError == nil {
            break
        }
        time.Sleep(time.Duration(attempts) * 1000 * time.Millisecond)
    }

    if pingError != nil {
        databasetest.StopContainer(t)
        t.Fatalf("waiting for database to be ready: %v", pingError)
    }

    if err := schema.Migrate(db); err != nil {
        db.Close()
        databasetest.StopContainer(t)
        t.Fatalf("migrating: %s", err)
    }

    // teardown is the function that should be invoked when the caller is done
    // with the database.
    teardown := func() {
        t.Helper()
        db.Close()
        databasetest.StopContainer(t)
    }

    return db, teardown
}
```

* Buat file unit test tests/user\_test.go

```text
package tests

import (
    "database/sql"
    "essentials/libraries/api"
    "essentials/models"
    "testing"

    _ "github.com/go-sql-driver/mysql"
    "github.com/google/go-cmp/cmp"
)

func TestUser(t *testing.T) {
    db, teardown := NewUnit(t)
    defer teardown()

    u := User{Db: db}
    t.Run("CRUD", u.Crud)
    t.Run("List", u.List)
}

// User struct for test users
type User struct {
    Db *sql.DB
}

//Crud : unit test  for create get and delete user function
func (u *User) Crud(t *testing.T) {
    u0 := models.User{
        Username: "Aladin",
        Email:    "aladin@gmail.com",
        Password: "1234",
        IsActive: false,
    }

    err := u0.Create(u.Db)
    if err != nil {
        t.Fatalf("creating user u0: %s", err)
    }

    u1 := models.User{
        ID: u0.ID,
    }

    err = u1.Get(u.Db)
    if err != nil {
        t.Fatalf("getting user u1: %s", err)
    }

    if diff := cmp.Diff(u1, u0); diff != "" {
        t.Fatalf("fetched != created:\n%s", diff)
    }

    u1.IsActive = false
    err = u1.Update(u.Db)
    if err != nil {
        t.Fatalf("update user u1: %s", err)
    }

    u2 := models.User{
        ID: u1.ID,
    }

    err = u2.Get(u.Db)
    if err != nil {
        t.Fatalf("getting user u2: %s", err)
    }

    if diff := cmp.Diff(u1, u2); diff != "" {
        t.Fatalf("fetched != updated:\n%s", diff)
    }

    err = u2.Delete(u.Db)
    if err != nil {
        t.Fatalf("delete user u2: %s", err)
    }

    u3 := models.User{
        ID: u2.ID,
    }

    err = u3.Get(u.Db)

    apiErr, ok := err.(*api.Error)
    if !ok || apiErr.Err != sql.ErrNoRows {
        t.Fatalf("getting user u3: %s", err)
    }
}

//List : unit test for user list function
func (u *User) List(t *testing.T) {
    u0 := models.User{
        Username: "Aladin",
        Email:    "aladin@gmail.com",
        Password: "1234",
        IsActive: false,
    }

    err := u0.Create(u.Db)
    if err != nil {
        t.Fatalf("creating user u0: %s", err)
    }

    var user models.User
    users, err := user.List(u.Db)
    if err != nil {
        t.Fatalf("listing users: %s", err)
    }
    if exp, got := 1, len(users); exp != got {
        t.Fatalf("expected users list size %v, got %v", exp, got)
    }
}
```

* `go test -v essentials/tests -run TestUser`

