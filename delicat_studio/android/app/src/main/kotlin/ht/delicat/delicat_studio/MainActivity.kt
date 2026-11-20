package ht.delicat.delicat_studio

import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine

class MainActivity : FlutterActivity() {
    private var video: VideoEngine? = null

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        video = VideoEngine(applicationContext).also { it.attach(flutterEngine.dartExecutor.binaryMessenger) }
    }

    override fun onDestroy() {
        video?.dispose()
        super.onDestroy()
    }
}
