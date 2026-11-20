// Voiceover recording from the microphone, placed at the playhead.
import * as A from './actions';
import { engine } from './engine';
import { icon } from './icons';
import { createMedia } from './media';
import { store } from './store';
import { $, shortTime, toast } from './utils';

export class Recorder {
  private rec: MediaRecorder | null = null;
  private stream: MediaStream | null = null;
  private chunks: Blob[] = [];
  private startT = 0;
  private began = 0;
  private timer: ReturnType<typeof setInterval> | undefined;
  private count = 0;
  private btn = $('#btn-voiceover');
  private label = $('#vo-label');
  private clock = $('#vo-timer');
  private muteBox = $<HTMLInputElement>('#vo-mute');

  constructor() {
    this.btn.addEventListener('click', () => void this.toggle());
  }

  get active(): boolean {
    return !!this.rec;
  }

  async toggle(): Promise<void> {
    if (this.rec) this.stop();
    else await this.start();
  }

  private async start(): Promise<void> {
    if (!navigator.mediaDevices?.getUserMedia) {
      toast('Recording is not supported in this browser', 'error');
      return;
    }
    try {
      this.stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } });
    } catch {
      toast('Microphone access was blocked', 'error');
      return;
    }
    const mime = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'].find((m) => MediaRecorder.isTypeSupported(m));
    this.rec = new MediaRecorder(this.stream, mime ? { mimeType: mime } : undefined);
    this.chunks = [];
    this.rec.ondataavailable = (e) => e.data.size && this.chunks.push(e.data);
    this.startT = store.time;
    this.began = performance.now();
    this.rec.start(200);
    if (store.project.clips.length) {
      engine.play();
      if (this.muteBox.checked) engine.setSpeakersMuted(true);
    }
    document.body.classList.add('is-recording');
    this.btn.innerHTML = icon('stop', 18);
    this.label.textContent = 'Stop recording';
    this.timer = setInterval(() => (this.clock.textContent = shortTime((performance.now() - this.began) / 1000)), 250);
  }

  private stop(): void {
    const rec = this.rec;
    if (!rec) return;
    this.rec = null;
    clearInterval(this.timer);
    const seconds = (performance.now() - this.began) / 1000;
    engine.pause();
    engine.setSpeakersMuted(false);
    document.body.classList.remove('is-recording');
    this.btn.innerHTML = icon('mic', 18);
    this.label.textContent = 'Record voiceover';
    this.clock.textContent = '0:00';
    rec.onstop = async () => {
      this.stream?.getTracks().forEach((t) => t.stop());
      this.stream = null;
      if (seconds < 0.3) return;
      const blob = new Blob(this.chunks, { type: rec.mimeType || 'audio/webm' });
      const ext = blob.type.includes('mp4') ? 'm4a' : blob.type.includes('ogg') ? 'ogg' : 'webm';
      const m = await createMedia(blob, `Voiceover ${++this.count}.${ext}`, { kind: 'audio' });
      if (!m) return;
      A.addMedia(m, { time: this.startT });
      toast('Voiceover added to the timeline', 'success');
    };
    rec.stop();
  }
}
