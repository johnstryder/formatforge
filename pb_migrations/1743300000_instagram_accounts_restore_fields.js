/// instagram_accounts sometimes exists with only system fields. Add missing schema + SQLite columns.
migrate((app) => {
  let col
  try {
    col = app.findCollectionByNameOrId("instagram_accounts")
  } catch (_) {
    return
  }
  const names = new Set((col.fields || []).map((f) => f.name))
  const toAdd = []
  if (!names.has("instagram_user_id")) {
    toAdd.push(new TextField({ name: "instagram_user_id", max: 0 }))
  }
  if (!names.has("username")) {
    toAdd.push(new TextField({ name: "username", max: 0 }))
  }
  if (!names.has("access_token")) {
    toAdd.push(new TextField({ name: "access_token", max: 0 }))
  }
  if (!names.has("token_expires_at")) {
    toAdd.push(new DateField({ name: "token_expires_at" }))
  }
  if (!names.has("is_active")) {
    toAdd.push(new BoolField({ name: "is_active" }))
  }
  if (toAdd.length === 0) {
    return
  }
  col.fields = [...col.fields, ...toAdd]
  app.save(col)
})
