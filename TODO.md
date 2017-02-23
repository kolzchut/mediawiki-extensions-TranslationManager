- Rename to "Translation Manager" or something similar
- [DONE] Add a 'status' type ('untranslated', 'translated', 'in translation', 'in review', ...)
- [DONE] Show all articles on special page - union of page, tp_translation, ...
- Add a line editor to change status/comments/suggested title
    - If the article is not yet in tp_translation, add it there
- Add hooks to handle all of the following article changes:
    1. New page ('Move to NS_MAIN')
    2. Page deletion (what to do here? Just notify?)
    3. Page rename (same?)
    4. Interwiki added
- Create an initial importer?
