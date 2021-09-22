# Request Response Helper

Decode Request dan set/write response adalah suatu kode yang ditulis berulang-ulang karena sering dipanggil. Untuk efisiensi dan memudahkan pemeliharaan kode, kita akan membuat hel request dan helper response

## Request Helper

* Buatlah file libraries/api/request.go

```go
package api

import (
    "encoding/json"
    "net/http"
)

// Decode reads the body of an HTTP request looking for a JSON document. The
// body is decoded into the provided value.
func Decode(r *http.Request, val interface{}) error {
    if err := json.NewDecoder(r.Body).Decode(val); err != nil {
        return err
    }

    return nil
}
```

* Ubah method Update pada file controllers/users.go agar memanggil helper request

```go
// Update user by id
func (u *Users) Update(w http.ResponseWriter, r *http.Request) {
    paramID := r.Context().Value(api.Ctx("ps")).(httprouter.Params).ByName("id")
    id, err := strconv.Atoi(paramID)
    if err != nil {
        u.Log.Println("convert param to id", err)
        w.WriteHeader(http.StatusInternalServerError)
        return
    }

    user := new(models.User)
    user.ID = uint64(id)
    err = user.Get(u.Db)
    if err != nil {
        u.Log.Println("Get User", err)
        w.WriteHeader(http.StatusInternalServerError)
        return
    }

    userRequest := new(request.UserRequest)
    err = api.Decode(r, &userRequest)
    if err != nil {
        u.Log.Printf("error decode user: %s", err)
        w.WriteHeader(http.StatusInternalServerError)
        return
    }

    userUpdate := userRequest.Transform(user)
    err = userUpdate.Update(u.Db)
    if err != nil {
        u.Log.Printf("error update user: %s", err)
        w.WriteHeader(http.StatusInternalServerError)
        return
    }

    resp := new(response.UserResponse)
    resp.Transform(userUpdate)
    data, err := json.Marshal(resp)
    if err != nil {
        u.Log.Println("Marshall data user", err)
        w.WriteHeader(http.StatusInternalServerError)
        return
    }

    w.Header().Set("Content-Type", "application/json; charset=utf-8")
    w.WriteHeader(http.StatusOK)
    if _, err := w.Write(data); err != nil {
        u.Log.Println("error writing result", err)
    }
}
```

* Ubah method Create pada file usecases/user\_usecase.go agar memanggil helper request

```go
// Create new user
func (u *UserUsecase) Create(r *http.Request) ([]byte, error) {
    var userRequest request.NewUserRequest
    var data []byte

    err := api.Decode(r, &userRequest)
    if err != nil {
        u.Log.Printf("error decode user: %s", err)
        return data, err
    }

    if userRequest.Password != userRequest.RePassword {
        err = errors.New("Password not match")
        u.Log.Printf("error : %s", err)
        return data, err
    }

    pass, err := bcrypt.GenerateFromPassword([]byte(userRequest.Password), bcrypt.DefaultCost)
    if err != nil {
        u.Log.Printf("error generate password: %s", err)
        return data, err
    }

    userRequest.Password = string(pass)

    user := userRequest.Transform()

    err = user.Create(u.Db)
    if err != nil {
        u.Log.Printf("error call create user: %s", err)
        return data, err
    }

    var res response.UserResponse
    res.Transform(user)
    data, err = json.Marshal(res)
    if err != nil {
        u.Log.Println("error marshalling result", err)
        return data, err
    }

    return data, nil
}
```

## Response Helper

* Buat file libraries/api/response.go

```go
package api

import (
    "encoding/json"
    "net/http"
)

// Response converts a Go value to JSON and sends it to the client.
func Response(w http.ResponseWriter, data interface{}, statusCode int) error {

    // Convert the response value to JSON.
    res, err := json.Marshal(data)
    if err != nil {
        return err
    }

    // Respond with the provided JSON.
    w.Header().Set("Content-Type", "application/json; charset=utf-8")
    w.WriteHeader(statusCode)
    if _, err := w.Write(res); err != nil {
        return err
    }

    return nil
}
```

* Ubah file usecases/user\_usecase.go menjadi

```go
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
        err = errors.New("Password not match")
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

    err = user.Create(u.Db)
    if err != nil {
        u.Log.Printf("error call create user: %s", err)
        return res, err
    }

    res.Transform(user)
    return res, nil
}
```

* Ubah file controllers/users.go menjadi 

```go
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
    list, err := user.List(u.Db)
    if err != nil {
        u.Log.Println("get user list", err)
        w.WriteHeader(http.StatusInternalServerError)
        return
    }

    var respList []response.UserResponse
    for _, l := range list {
        var resp response.UserResponse
        resp.Transform(&l)
        respList = append(respList, resp)
    }

    if err = api.Response(w, respList, http.StatusOK); err != nil {
        u.Log.Println("error response", err)
        w.WriteHeader(http.StatusInternalServerError)
        return
    }
}

// Create new user
func (u *Users) Create(w http.ResponseWriter, r *http.Request) {

    uc := usecases.UserUsecase{Log: u.Log, Db: u.Db}
    resp, err := uc.Create(r)
    if err != nil {
        w.WriteHeader(http.StatusInternalServerError)
        return
    }

    if err = api.Response(w, resp, http.StatusOK); err != nil {
        u.Log.Println("error response", err)
        w.WriteHeader(http.StatusInternalServerError)
        return
    }
}

// View user by id
func (u *Users) View(w http.ResponseWriter, r *http.Request) {
    paramID := r.Context().Value(api.Ctx("ps")).(httprouter.Params).ByName("id")
    id, err := strconv.Atoi(paramID)
    if err != nil {
        u.Log.Println("convert param to id", err)
        w.WriteHeader(http.StatusInternalServerError)
        return
    }

    user := new(models.User)
    user.ID = uint64(id)
    err = user.Get(u.Db)
    if err != nil {
        u.Log.Println("Get User", err)
        w.WriteHeader(http.StatusInternalServerError)
        return
    }

    resp := new(response.UserResponse)
    resp.Transform(user)
    if err = api.Response(w, resp, http.StatusOK); err != nil {
        u.Log.Println("error response", err)
        w.WriteHeader(http.StatusInternalServerError)
        return
    }
}

// Update user by id
func (u *Users) Update(w http.ResponseWriter, r *http.Request) {
    paramID := r.Context().Value(api.Ctx("ps")).(httprouter.Params).ByName("id")
    id, err := strconv.Atoi(paramID)
    if err != nil {
        u.Log.Println("convert param to id", err)
        w.WriteHeader(http.StatusInternalServerError)
        return
    }

    user := new(models.User)
    user.ID = uint64(id)
    err = user.Get(u.Db)
    if err != nil {
        u.Log.Println("Get User", err)
        w.WriteHeader(http.StatusInternalServerError)
        return
    }

    userRequest := new(request.UserRequest)
    err = api.Decode(r, &userRequest)
    if err != nil {
        u.Log.Printf("error decode user: %s", err)
        w.WriteHeader(http.StatusInternalServerError)
        return
    }

    userUpdate := userRequest.Transform(user)
    err = userUpdate.Update(u.Db)
    if err != nil {
        u.Log.Printf("error update user: %s", err)
        w.WriteHeader(http.StatusInternalServerError)
        return
    }

    resp := new(response.UserResponse)
    resp.Transform(userUpdate)
    if err = api.Response(w, resp, http.StatusOK); err != nil {
        u.Log.Println("error response", err)
        w.WriteHeader(http.StatusInternalServerError)
        return
    }
}

// Delete user by id
func (u *Users) Delete(w http.ResponseWriter, r *http.Request) {
    paramID := r.Context().Value(api.Ctx("ps")).(httprouter.Params).ByName("id")
    id, err := strconv.Atoi(paramID)
    if err != nil {
        u.Log.Println("convert param to id", err)
        w.WriteHeader(http.StatusInternalServerError)
        return
    }

    user := new(models.User)
    user.ID = uint64(id)
    err = user.Get(u.Db)
    if err != nil {
        u.Log.Println("Get User", err)
        w.WriteHeader(http.StatusInternalServerError)
        return
    }

    err = user.Delete(u.Db)
    if err != nil {
        u.Log.Println("Delete User", err)
        w.WriteHeader(http.StatusInternalServerError)
        return
    }

    w.Header().Set("Content-Type", "application/json; charset=utf-8")
    w.WriteHeader(http.StatusNoContent)
}
```

