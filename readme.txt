Example, when i am not using a callback (no callback mode) 

i've already create user 'lemper' with curl 

curl -X 'POST' \
  'https://sc4-api-en.dreamgates.net/v4/user/create' \
  -H 'accept: application/json' \
  -H 'Authorization: Bearer 3feda8c2-86a0-4636-8194-e68fa0d47372' \
  -H 'Content-Type: application/json' \
  -d '{
  "name": "lemper"
}'

the response is 

{
    "code": 0,
    "message": "OK",
    "data": {
        "user_code": 400236733
    }
}

So, i start using the callback again with the code [callbacksu.php & config.php] 

by url 

https://cbk.hyperasterix.com/

then i test the dreamgates endpoint using request 

curl -X POST 'https://sc4-api-en.dreamgates.net/v4/user/info' -H 'Authorization: Bearer 3feda8c2-86a0-4636-8194-e68fa0d47372' -H 'Content-Type: application/json' -d '{"user_code": "400236733"}'

the response is 

{"code":2002,"message":"USER_NOT_FOUND"}

BUT, if i am turn of [no callback mode] then i test the dreamgates endpoint using request

curl -X POST 'https://sc4-api-en.dreamgates.net/v4/user/info' -H 'Authorization: Bearer 3feda8c2-86a0-4636-8194-e68fa0d47372' -H 'Content-Type: application/json' -d '{"user_code": "400236733"}'on/json' -d '{"user_code": "400236733"}'

{"code":0,"message":"OK","data":{"name":"lemper","balance":0.0000}}

See ? the point is, it would not work with callback, ok if you think my callback is problem, so any other information else ?
