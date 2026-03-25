/// Restore API rules so dashboard users (PocketBase auth records) can use the app via pb_proxy.
/// Fixes "Only superusers can perform this action" when listRule/viewRule were cleared or left superuser-only.
/// Safe and idempotent: overwrites rule strings to FormatForge defaults. Restart PocketBase to apply.
migrate((app) => {
  const auth = '@request.auth.id != ""'

  const applyUserCrud = (name) => {
    let col
    try {
      col = app.findCollectionByNameOrId(name)
    } catch (_) {
      return
    }
    col.listRule = auth
    col.viewRule = auth
    col.createRule = auth
    col.updateRule = auth
    col.deleteRule = auth
    app.save(col)
  }

  applyUserCrud('social_accounts')
  applyUserCrud('input_media')
  applyUserCrud('output_media')

  let pipelines
  try {
    pipelines = app.findCollectionByNameOrId('pipelines')
  } catch (_) {
    return
  }
  pipelines.listRule = auth
  pipelines.viewRule = auth
  pipelines.createRule = null
  pipelines.updateRule = null
  pipelines.deleteRule = null
  app.save(pipelines)
})
