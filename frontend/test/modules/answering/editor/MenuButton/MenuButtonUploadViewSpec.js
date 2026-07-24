import { uploadTag } from 'modules/answering/editor/MenuButton/MenuButtonUploadView';
import { Model } from 'backbone';

describe('answering form', function () {
  describe('uploader builds the insert tag', function () {
    it('known file', function () {
      const tag = uploadTag(new Model({ 'mime': 'plain/text', 'name': 'foo.txt' }));
      expect(tag.getTag()).toEqual('file');
      expect(tag.getAttributes()).toEqual('src=upload');
      expect(tag.getContent()).toEqual('foo.txt');
    });

    it('unknown file', function () {
      const tag = uploadTag(new Model({ 'mime': 'foo/bar', 'name': 'foo.txt' }));
      expect(tag.getTag()).toEqual('file');
      expect(tag.getAttributes()).toEqual('src=upload');
      expect(tag.getContent()).toEqual('foo.txt');
    });

    it('image', function () {
      const tag = uploadTag(new Model({ 'mime': 'image/jpeg', 'name': 'foo.jpg' }));
      expect(tag.getTag()).toEqual('img');
      expect(tag.getAttributes()).toEqual('src=upload');
      expect(tag.getContent()).toEqual('foo.jpg');
    });

    it('audio', function () {
      const tag = uploadTag(new Model({ 'mime': 'audio/mpeg', 'name': 'foo.mp3' }));
      expect(tag.getTag()).toEqual('audio');
      expect(tag.getAttributes()).toEqual('src=upload');
      expect(tag.getContent()).toEqual('foo.mp3');
    });

    it('video', function () {
      const tag = uploadTag(new Model({ 'mime': 'video/mp4', 'name': 'foo.mp4' }));
      expect(tag.getTag()).toEqual('video');
      expect(tag.getAttributes()).toEqual('src=upload');
      expect(tag.getContent()).toEqual('foo.mp4');
    });
  });
});
