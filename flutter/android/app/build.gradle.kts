plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

android {
    namespace = "com.delicat.studio"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = "com.delicat.studio"
        // ffmpeg-kit's shared libraries require 24 or newer, which is above
        // Flutter's own floor, so it is pinned here rather than inherited.
        minSdk = 24
        targetSdk = flutter.targetSdkVersion
        // Uses the version code from pubspec.yaml. When using split APKs, 1000 * ABI_VERSION
        // is added automatically by Flutter. (https://developer.android.com/studio/build/configure-apk-splits#configure-APK-versions)
        // You can force using the value of versionCode by specifying the `-P force-version-code-ignoring-abi=true`
        // flag during build.
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    buildTypes {
        release {
            // Debug keys for now: a release build that cannot be installed is
            // not much use, and a real key belongs in CI secrets rather than
            // in the repository.
            signingConfig = signingConfigs.getByName("debug")

            // Shrinking is off deliberately. Dart is compiled ahead of time
            // rather than to JVM bytecode, so R8 has very little of this app
            // to work on, while ffmpeg-kit reaches its classes over JNI where
            // R8 cannot see the references — stripping one of those fails at
            // runtime, on the device, with a linkage error rather than a build
            // error. A few megabytes is not worth that trade.
            isMinifyEnabled = false
            isShrinkResources = false
        }
    }

    packaging {
        resources {
            // ffmpeg-kit and its transitive AARs each carry a copy of these.
            excludes += setOf(
                "META-INF/AL2.0",
                "META-INF/LGPL2.1",
                "META-INF/LICENSE*",
                "META-INF/NOTICE*",
            )
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
