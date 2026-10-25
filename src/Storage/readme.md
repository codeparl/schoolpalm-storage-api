# Storage implementation notes

This directory contains the Laravel filesystem implementation and disk builder. The public contract, context model, settings resolution, and provider registry are documented at the package root:

- [Architecture](../../docs/architecture.md)
- [API reference](../../docs/api.md)
- [Context integration](../../docs/context-integration.md)
- [Settings integration](../../docs/settings-integration.md)
- [Security](../../docs/security.md)

`StorageWorkspaceInterface` and `DocumentContent` remain available for existing document-workspace integrations. They are optional extensions and do not make the core storage API depend on a document-generation package.
