# Validation

* Setiap payload request yang masuk harus divalidasi
* Kita akan menggunakan [validator v.9](https://github.com/waresix/golang-guidance/tree/ed3e782c3d335790b55cda7cba53952493f67e51/gopkg.in/go-playground/validator.v9)
* Setiap struct di payload request ditambahkan tag validator

```go
package request

import (
    "essentials/models"
    "strconv"
)

// NewUserRequest : format json request for new user
type NewUserRequest struct {
    Username   string `json:"username" validate:"required"`
    Email      string `json:"email" validate:"required"`
    Password   string `json:"password" validate:"required"`
    RePassword string `json:"re_password" validate:"required"`
}

// Transform NewUserRequest to User
func (u *NewUserRequest) Transform() *models.User {
    var user models.User
    user.Username = u.Username
    user.Email = u.Email
    user.Password = u.Password

    return &user
}

// UserRequest : format json request for update user
type UserRequest struct {
    ID       uint64 `json:"id" validate:"required"`
    IsActive string `json:"is_active"`
}

// Transform UserRequest to User
func (u *UserRequest) Transform(user *models.User) *models.User {
    if u.ID == user.ID {
        if len(u.IsActive) > 0 {
            user.IsActive, _ = strconv.ParseBool(u.IsActive)
        }
    }

    return user
}
```

* Pengecekan bisa dilakukan secara generic di helper request libaries/api/request.go

```go
package api

import (
    "encoding/json"
    "errors"
    "net/http"

    "gopkg.in/go-playground/validator.v9"
)

var validate *validator.Validate

// Decode reads the body of an HTTP request looking for a JSON document. The
// body is decoded into the provided value.
func Decode(r *http.Request, val interface{}) error {
    if err := json.NewDecoder(r.Body).Decode(val); err != nil {
        return ErrBadRequest(err, "")
    }

    validate = validator.New()

    if err := validate.Struct(val); err != nil {
        if _, ok := err.(*validator.InvalidValidationError); ok {
            return err
        }

        for _, verr := range err.(validator.ValidationErrors) {
            err = errors.New(verr.Field() + " is " + verr.Tag())
            break
        }

        if err != nil {
            return ErrBadRequest(err, err.Error())
        }
    }

    return nil
}
```

* Adakalanya kita ingin validasi dilakukan secara khusus di suatu payload request. Untuk itu kita akan membuat validasi di helper request adalah optional.

```go
package api

import (
    "encoding/json"
    "errors"
    "net/http"

    "gopkg.in/go-playground/validator.v9"
)

// Decode reads the body of an HTTP request looking for a JSON document. The
// body is decoded into the provided value.
func Decode(r *http.Request, val interface{}, mustValidate bool) error {
    if err := json.NewDecoder(r.Body).Decode(val); err != nil {
        return ErrBadRequest(err, "")
    }

    if mustValidate {
        validate := validator.New()

        if err := validate.Struct(val); err != nil {
            if _, ok := err.(*validator.InvalidValidationError); ok {
                return err
            }

            for _, verr := range err.(validator.ValidationErrors) {
                err = errors.New(verr.Field() + " is " + verr.Tag())
                break
            }

            if err != nil {
                return ErrBadRequest(err, err.Error())
            }
        }
    }

    return nil
}
```

* Kemudian kita tambahkan validasi khusus di payload request yang membutuhkan validasi khusus.

```go
package request

import (
    "errors"
    "essentials/libraries/api"
    "essentials/models"
    "strconv"

    "gopkg.in/go-playground/validator.v9"
)

// NewUserRequest : format json request for new user
type NewUserRequest struct {
    Username   string `json:"username" validate:"required"`
    Email      string `json:"email" validate:"required"`
    Password   string `json:"password" validate:"required"`
    RePassword string `json:"re_password" validate:"required"`
}

// Transform NewUserRequest to User
func (u *NewUserRequest) Transform() *models.User {
    var user models.User
    user.Username = u.Username
    user.Email = u.Email
    user.Password = u.Password

    return &user
}

// Validate NewUserRequest
func (u *NewUserRequest) Validate() error {
    validate := validator.New()

    if err := validate.Struct(u); err != nil {
        if _, ok := err.(*validator.InvalidValidationError); ok {
            return err
        }

        for _, verr := range err.(validator.ValidationErrors) {
            err = errors.New(verr.Field() + " is " + verr.Tag())
            break
        }

        if err != nil {
            return api.ErrBadRequest(err, err.Error())
        }
    }

    return nil
}

// UserRequest : format json request for update user
type UserRequest struct {
    ID       uint64 `json:"id" validate:"required"`
    IsActive string `json:"is_active"`
}

// Transform UserRequest to User
func (u *UserRequest) Transform(user *models.User) *models.User {
    if u.ID == user.ID {
        if len(u.IsActive) > 0 {
            user.IsActive, _ = strconv.ParseBool(u.IsActive)
        }
    }

    return user
}
```

* Selanjutnya setiap call api.Decode\(\) perlu memberitahu apakah akan menggunakan validasi global atau tidak dengan melempar parameter boolean musValidate. Dan mungkin perlu memanggil fungsi Validate khusus `userRequest.Validate()`
* Berikut contoh perubahan di file usecases/user\_usecase.go

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

    err := api.Decode(r, &userRequest, false)
    if err != nil {
        u.Log.Printf("error decode user: %s", err)
        return res, err
    }

    err = userRequest.Validate()
    if err != nil {
        u.Log.Printf("validate new user: %s", err)
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

* Dan ini contoh validasi yang dilakukan secara global di helper request pada method Update di file controllers/users.go

```go
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
    err = api.Decode(r, &userRequest, true)
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
```

