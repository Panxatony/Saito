## Images and files

Signed-in members can upload files of their own and place them in postings. What
is allowed is up to the operator — usually images, video, audio and plain text
files. SVG is deliberately excluded, because it can carry scripts.

### Uploading

In the editor, the button with the picture icon opens an area that does both:
takes new files and shows your archive. Files can be picked or dropped onto the
marked area, several at a time; each one then reports whether it was accepted.

Large images are scaled down on upload so that postings stay quick to load over
a slow connection. Images with an extreme resolution are refused — not out of
strictness, but because decoding such a file can bring a server to its knees.

### Inserting

In the archive a click on a tile selects it, and several can be selected at
once. *Insert selection* places them at the cursor. The right markup is chosen
by the kind of file, so an image arrives as an image and a video as a playable
video.

### Covering the delicate

Next to *Insert selection* sits a tick box, **Insert as NSFW**. With it set, what
you insert arrives covered: blurred, with a note over it. One click shows it,
another puts it away again. On a video the play button stays out of reach for as
long as the cover is on.

The tick applies to **this insertion**, not to the file. The same picture can be
covered in one posting and plain in another. Which also means: marking a file
afterwards changes nothing about postings already written — there the markup has
to be added to the text.

Editing a posting by hand, you write it yourself:

```
[img src=upload nsfw=1]filename.jpg[/img]
```

The same works for `[video]`, `[audio]` and `[file]`.

One honest word: the cover keeps the picture off the screen, not away from the
viewer. The file itself sits on the server unchanged and can be fetched by its
address at any time. It guards against something appearing unexpectedly in an
open-plan office; it is not access control.

### Managing

Your profile holds the full archive. Files can be selected there and deleted
together. Deleting asks once for the whole selection, because it cannot be
undone.

A deleted file also disappears from the postings that embedded it, leaving a
reference with nothing behind it.
