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

The editor toolbar — beside bold, uploads and preview — carries an **NSFW**
button. With it pressed the whole posting arrives covered: images, video and
files blurred under a note, plus a red badge beside the subject. One click shows
what is underneath.

For a **picture** every further click puts it back — blurred, clear, blurred.
The price is that the full-size view of a marked picture is no longer one click
away, because every click works the cover. **Video, audio and files** behave
differently: their controls — play, seek, download — sit *inside* the media, so
the cover shrinks to a small tab in the corner and is drawn back from there.
While it is on, the play button stays out of reach.

The button is in *every* editor: starting a thread, answering one, and editing.
A reply does not **inherit** the marking, though — it is its author's own posting
and is judged on its own.

Editing the text by hand, single insertions can be marked too:

```
[img src=upload nsfw=1]filename.jpg[/img]
```

The same works for `[video]`, `[audio]` and `[file]`, and it is the finer
instrument when only one picture out of several needs covering.

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
