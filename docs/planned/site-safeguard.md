# Site Safeguard

An independent backup, restore, and clean-deployment plugin for Grav.

Its first milestone will create profile-driven archives with manifests and
checksums, omit caches and host debris, validate an upload in staging, enter
maintenance mode only for promotion, preserve a rollback package, and never
replace the running site destructively inside the initiating web request.

It must coexist with Grav Commander: Commander remains the trusted operator's
file/backup workbench; Site Safeguard owns verified deployment and restore
orchestration. Neither reads the other's private data directly.
