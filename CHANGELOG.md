# Changelog: zerofront/module-zero-ai-assistant

## 1.3.9

- New in the free set (it was a licensed module before): a chat in the admin (menu AI Assistant) that looks up and creates products, categories, CMS pages and blocks, and handles orders, within the admin user's own role. Every change is a card the user confirms first, with Undo.
- Bulk product texts go through a queue (`zero.ai.bulk`) with progress, Stop and Undo All; photos uploaded into the chat become product, category or page images.
- Tools for Zero Blog, Zero CMS, Quote Request, Returns, Reviews and Banner, and for the licensed Nova Poshta, Ukrposhta, Checkbox and Staging modules, appear when those modules are installed and enabled; the package needs none of them and compiles without them.
- It uses the Anthropic API key of Zero CMS (Zero > CMS > AI); without a key the chat has nothing to ask. The model and your own instructions are set in Stores > Configuration > Zero > AI Assistant.

Earlier releases (1.3.5 and before): see the Git tags.
