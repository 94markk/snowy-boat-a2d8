package ht.delicat.delicat_studio

import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine

class MainActivity : FlutterActivity() {
    private var video: VideoEngine? = null
    private var system: SystemChannel? = null

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        val messenger = flutterEngine.dartExecutor.binaryMessenger
        video = VideoEngine(applicationContext).also { it.attach(messenger) }
        system = SystemChannel(this).also { it.attach(messenger) }
    }

    override fun onDestroy() {
        video?.dispose()
        system?.dispose()
        super.onDestroy()
    }
}
