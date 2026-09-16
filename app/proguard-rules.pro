# media3 resolves renderers and extractors reflectively, so R8 cannot see the
# references and will strip classes the player needs at runtime.
-keep class androidx.media3.** { *; }
-dontwarn androidx.media3.**
