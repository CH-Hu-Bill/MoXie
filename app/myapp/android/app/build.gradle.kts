plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

import java.util.Properties
import java.io.FileInputStream

fun loadKeystoreProperties(): Map<String, String> {
    val props = Properties()
    val propFile = rootProject.file("key.properties")
    if (propFile.exists()) {
        props.load(FileInputStream(propFile))
    }
    return mapOf(
        "storeFile" to (props.getProperty("storeFile") ?: ""),
        "storePassword" to (props.getProperty("storePassword") ?: ""),
        "keyPassword" to (props.getProperty("keyPassword") ?: ""),
        "keyAlias" to (props.getProperty("keyAlias") ?: ""),
    )
}

android {
    namespace = "com.example.myapp"
    compileSdk = 36
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "com.example.myapp"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        create("release") {
            val keystore = loadKeystoreProperties()
            if (keystore["storeFile"]!!.isNotEmpty()) {
                storeFile = rootProject.file(keystore["storeFile"]!!)
                storePassword = keystore["storePassword"]
                keyAlias = keystore["keyAlias"]
                keyPassword = keystore["keyPassword"]
            }
        }
    }

    buildTypes {
        release {
            signingConfig = signingConfigs.getByName("release")
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
