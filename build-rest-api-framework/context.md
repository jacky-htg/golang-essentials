# Context

[Context](https://blog.golang.org/context) adalah salah satu konkuresi pattern yang bertujuan untuk mengcancel jika menemui sebuah routine yang waktu eksekusinya lama. Karena operasi yang berjalan lama memang seharusnya diberi deadline. Jalan untuk meng-handle pembatalan adalah dengan melempar context.Context to fungsi yang mengetahui proses untuk mengecek pembatalan terminasi dini.

* Tambahkan argumen context.Context ke semua fungsi di models/user.go
* Passing ctx variable ke db.QueryContext, db.QueryRowContext, db.PrepareContext and stmt.ExecContext di file models/user.go

```text
package models

import (
    "context"
    "database/sql"
    "essentials/libraries/api"
)

// User : struct of User
type User struct {
    ID       uint64
    Username string
    Password string
    Email    string
    IsActive bool
}

// List of users
func (u *User) List(ctx context.Context, db *sql.DB) ([]User, error) {
    var list []User
    const q = `SELECT id, username, password, email, is_active FROM users`

    rows, err := db.QueryContext(ctx, q)
    if err != nil {
        return list, err
    }

    defer rows.Close()

    for rows.Next() {
        var user User
        if err := rows.Scan(&user.ID, &user.Username, &user.Password, &user.Email, &user.IsActive); err != nil {
            return list, err
        }
        list = append(list, user)
    }

    return list, rows.Err()
}

// Create new user
func (u *User) Create(ctx context.Context, db *sql.DB) error {
    const query = `
        INSERT INTO users (username, password, email, is_active, created, updated)
        VALUES (?, ?, ?, 0, NOW(), NOW())
    `
    stmt, err := db.PrepareContext(ctx, query)

    if err != nil {
        return err
    }

    defer stmt.Close()

    res, err := stmt.ExecContext(ctx, u.Username, u.Password, u.Email)
    if err != nil {
        return err
    }

    id, err := res.LastInsertId()
    if err != nil {
        return err
    }

    u.ID = uint64(id)

    return nil
}

// Get user by id
func (u *User) Get(ctx context.Context, db *sql.DB) error {
    const q string = `SELECT id, username, password, email, is_active FROM users`
    err := db.QueryRowContext(ctx, q+" WHERE id=?", u.ID).Scan(&u.ID, &u.Username, &u.Password, &u.Email, &u.IsActive)

    if err == sql.ErrNoRows {
        err = api.ErrNotFound(err, "")
    }

    return err
}

// Update user by id
func (u *User) Update(ctx context.Context, db *sql.DB) error {
    const q string = `UPDATE users SET is_active = ? WHERE id = ?`
    stmt, err := db.PrepareContext(ctx, q)
    if err != nil {
        return err
    }

    defer stmt.Close()

    _, err = stmt.ExecContext(ctx, u.IsActive, u.ID)
    return err
}

// Delete user by id
func (u *User) Delete(ctx context.Context, db *sql.DB) error {
    const q string = `DELETE FROM users WHERE id = ?`
    stmt, err := db.PrepareContext(ctx, q)
    if err != nil {
        return err
    }

    defer stmt.Close()

    _, err = stmt.ExecContext(ctx, u.ID)
    return err
}
```

* Pass nilai dari r.Context\(\) dari file controllers/users.go pada setiap kali memanggil method di models/user.go

```text
package controllers

import (
    "database/sql"
    "essentials/libraries/api"
    "essentials/models"
    "essentials/payloads/request"
    "essentials/payloads/response"
    "essentials/usecases"
    "log"
    "net/http"
    "strconv"

    "github.com/julienschmidt/httprouter"
)

// Users : struct for set Users Dependency Injection
type Users struct {
    Db  *sql.DB
    Log *log.Logger
}

// List : http handler for returning list of users
func (u *Users) List(w http.ResponseWriter, r *http.Request) {
    user := new(models.User)
    list, err := user.List(r.Context(), u.Db)
    if err != nil {
        u.Log.Println("get user list", err)
        api.ResponseError(w, err)
        return
    }

    var respList []response.UserResponse
    for _, l := range list {
        var resp response.UserResponse
        resp.Transform(&l)
        respList = append(respList, resp)
    }

    api.ResponseOK(w, respList, http.StatusOK)
}

// Create new user
func (u *Users) Create(w http.ResponseWriter, r *http.Request) {

    uc := usecases.UserUsecase{Log: u.Log, Db: u.Db}
    resp, err := uc.Create(r)
    if err != nil {
        api.ResponseError(w, err)
        return
    }

    api.ResponseOK(w, resp, http.StatusCreated)
}

// View user by id
func (u *Users) View(w http.ResponseWriter, r *http.Request) {
    paramID := r.Context().Value(api.Ctx("ps")).(httprouter.Params).ByName("id")
    id, err := strconv.Atoi(paramID)
    if err != nil {
        u.Log.Println("convert param to id", err)
        api.ResponseError(w, err)
        return
    }

    user := new(models.User)
    user.ID = uint64(id)
    err = user.Get(r.Context(), u.Db)
    if err != nil {
        u.Log.Println("Get User", err)
        api.ResponseError(w, err)
        return
    }

    resp := new(response.UserResponse)
    resp.Transform(user)
    api.ResponseOK(w, resp, http.StatusOK)
}

// Update user by id
func (u *Users) Update(w http.ResponseWriter, r *http.Request) {
    paramID := r.Context().Value(api.Ctx("ps")).(httprouter.Params).ByName("id")
    id, err := strconv.Atoi(paramID)
    if err != nil {
        u.Log.Println("convert param to id", err)
        api.ResponseError(w, err)
        return
    }

    user := new(models.User)
    user.ID = uint64(id)
    err = user.Get(r.Context(), u.Db)
    if err != nil {
        u.Log.Println("Get User", err)
        api.ResponseError(w, err)
        return
    }

    userRequest := new(request.UserRequest)
    err = api.Decode(r, &userRequest)
    if err != nil {
        u.Log.Printf("error decode user: %s", err)
        api.ResponseError(w, err)
        return
    }

    userUpdate := userRequest.Transform(user)
    err = userUpdate.Update(r.Context(), u.Db)
    if err != nil {
        u.Log.Printf("error update user: %s", err)
        api.ResponseError(w, err)
        return
    }

    resp := new(response.UserResponse)
    resp.Transform(userUpdate)
    api.ResponseOK(w, resp, http.StatusOK)
}

// Delete user by id
func (u *Users) Delete(w http.ResponseWriter, r *http.Request) {
    paramID := r.Context().Value(api.Ctx("ps")).(httprouter.Params).ByName("id")
    id, err := strconv.Atoi(paramID)
    if err != nil {
        u.Log.Println("convert param to id", err)
        api.ResponseError(w, err)
        return
    }

    user := new(models.User)
    user.ID = uint64(id)
    err = user.Get(r.Context(), u.Db)
    if err != nil {
        u.Log.Println("Get User", err)
        api.ResponseError(w, err)
        return
    }

    err = user.Delete(r.Context(), u.Db)
    if err != nil {
        u.Log.Println("Delete User", err)
        api.ResponseError(w, err)
        return
    }
    api.ResponseOK(w, nil, http.StatusNoContent)
}
```

* Pass nilai dari r.Context\(\) dari file usecases/user\_usecase.go pada setiap kali memanggil method di models/user.go

```text
package usecases

import (
    "database/sql"
    "errors"
    "essentials/libraries/api"
    "essentials/payloads/request"
    "essentials/payloads/response"
    "log"
    "net/http"

    "golang.org/x/crypto/bcrypt"
)

// UserUsecase struct
type UserUsecase struct {
    Log *log.Logger
    Db  *sql.DB
}

// Create new user
func (u *UserUsecase) Create(r *http.Request) (response.UserResponse, error) {
    var userRequest request.NewUserRequest
    var res response.UserResponse

    err := api.Decode(r, &userRequest)
    if err != nil {
        u.Log.Printf("error decode user: %s", err)
        return res, err
    }

    if userRequest.Password != userRequest.RePassword {
        err = api.ErrBadRequest(errors.New("Password not match"), "")
        u.Log.Printf("error : %s", err)
        return res, err
    }

    pass, err := bcrypt.GenerateFromPassword([]byte(userRequest.Password), bcrypt.DefaultCost)
    if err != nil {
        u.Log.Printf("error generate password: %s", err)
        return res, err
    }

    userRequest.Password = string(pass)

    user := userRequest.Transform()

    err = user.Create(r.Context(), u.Db)
    if err != nil {
        u.Log.Printf("error call create user: %s", err)
        return res, err
    }

    res.Transform(user)
    return res, nil
}
```

* Pada unit test, pass nilai dari context.Background\(\) di file tests/user\_tes.go setiap memanggil method di models/user.go

```text
package tests

import (
    "context"
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
    ctx := context.Background()

    u0 := models.User{
        Username: "Aladin",
        Email:    "aladin@gmail.com",
        Password: "1234",
        IsActive: false,
    }

    err := u0.Create(ctx, u.Db)
    if err != nil {
        t.Fatalf("creating user u0: %s", err)
    }

    u1 := models.User{
        ID: u0.ID,
    }

    err = u1.Get(ctx, u.Db)
    if err != nil {
        t.Fatalf("getting user u1: %s", err)
    }

    if diff := cmp.Diff(u1, u0); diff != "" {
        t.Fatalf("fetched != created:\n%s", diff)
    }

    u1.IsActive = false
    err = u1.Update(ctx, u.Db)
    if err != nil {
        t.Fatalf("update user u1: %s", err)
    }

    u2 := models.User{
        ID: u1.ID,
    }

    err = u2.Get(ctx, u.Db)
    if err != nil {
        t.Fatalf("getting user u2: %s", err)
    }

    if diff := cmp.Diff(u1, u2); diff != "" {
        t.Fatalf("fetched != updated:\n%s", diff)
    }

    err = u2.Delete(ctx, u.Db)
    if err != nil {
        t.Fatalf("delete user u2: %s", err)
    }

    u3 := models.User{
        ID: u2.ID,
    }

    err = u3.Get(ctx, u.Db)

    apiErr, ok := err.(*api.Error)
    if !ok || apiErr.Err != sql.ErrNoRows {
        t.Fatalf("getting user u3: %s", err)
    }
}

//List : unit test for user list function
func (u *User) List(t *testing.T) {
    ctx := context.Background()

    u0 := models.User{
        Username: "Aladin",
        Email:    "aladin@gmail.com",
        Password: "1234",
        IsActive: false,
    }

    err := u0.Create(ctx, u.Db)
    if err != nil {
        t.Fatalf("creating user u0: %s", err)
    }

    var user models.User
    users, err := user.List(ctx, u.Db)
    if err != nil {
        t.Fatalf("listing users: %s", err)
    }
    if exp, got := 1, len(users); exp != got {
        t.Fatalf("expected users list size %v, got %v", exp, got)
    }
}
```

* Kita bisa mengetesnya dengan membuat time.Sleep\(\)

