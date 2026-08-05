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
        "storeFile" to (props.getProperty("storeFile") ?: System.getenv("KEYSTORE_FILE") ?: ""),
        "storePassword" to (props.getProperty("storePassword") ?: System.getenv("KEY_STORE_PASS") ?: ""),
        "keyPassword" to (props.getProperty("keyPassword") ?: System.getenv("KEY_KEY_PASS") ?: ""),
        "keyAlias" to (props.getProperty("keyAlias") ?: System.getenv("KEY_ALIAS") ?: ""),
    )
}

android {
    namespace = "billspace.listenwrite.flutter"
    compileSdk = 36
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = "billspace.listenwrite.flutter"
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
